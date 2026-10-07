<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\Stripe\StripeGateway;

/** Gateway de Stripe falso: registra las llamadas y devuelve URLs de juguete. */
function fakeStripe(bool $configured = true, ?Throwable $failWith = null): object
{
    $fake = new class($configured, $failWith) implements StripeGateway
    {
        public array $checkouts = [];

        public array $portals = [];

        public function __construct(private readonly bool $configured, private readonly ?Throwable $failWith) {}

        public function configured(): bool
        {
            return $this->configured;
        }

        public function checkoutUrl(Organization $organization, Plan $plan, User $user, ?string $customerId): string
        {
            if ($this->failWith) {
                throw $this->failWith;
            }
            $this->checkouts[] = compact('organization', 'plan', 'user', 'customerId');

            return "https://checkout.example/{$plan->slug}";
        }

        public function portalUrl(string $customerId): string
        {
            if ($this->failWith) {
                throw $this->failWith;
            }
            $this->portals[] = $customerId;

            return "https://portal.example/{$customerId}";
        }
    };
    app()->instance(StripeGateway::class, $fake);

    return $fake;
}

beforeEach(function () {
    Plan::where('slug', 'starter')->update(['stripe_price_id' => 'price_starter']);
    Plan::where('slug', 'business')->update(['stripe_price_id' => 'price_business']);
});

// ---------- resumen ----------

test('el resumen muestra plan, estado, consumo y planes disponibles', function () {
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDays(9), 'current_period_start' => now()->subDays(5), 'current_period_end' => now()->addDays(9)]);
    usage($organization, 250);
    fakeStripe();

    $response = $this->actingAs($user)->getJson('/api/billing')->assertOk();

    expect($response->json('plan.slug'))->toBe('starter')
        ->and($response->json('plan.max_monthly_messages'))->toBe(1000)
        ->and($response->json('plan.features'))->toBe(['human_inbox' => false, 'widget_customization' => false, 'analytics' => false])
        ->and($response->json('plan.max_users'))->toBe(1)
        ->and($response->json('users'))->toBe(['members' => 1, 'limit' => 1])
        ->and($response->json('state'))->toBe('trialing')
        ->and($response->json('trial_ends_at'))->not->toBeNull()
        ->and($response->json('usage'))->toBe(['messages_used' => 250, 'messages_limit' => 1000, 'percent' => 25])
        ->and($response->json('agents'))->toBe(['active' => 0, 'limit' => 1])
        ->and($response->json('stripe'))->toBe(['configured' => true, 'has_customer' => false, 'paying' => false])
        ->and($response->json('can_manage'))->toBeTrue()
        ->and(collect($response->json('plans'))->pluck('slug')->all())->toBe(['starter', 'business', 'pro']);
});

test('el porcentaje de consumo no pasa de 100', function () {
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual']);
    usage($organization, 1500);

    $this->actingAs($user)->getJson('/api/billing')->assertJsonPath('usage.percent', 100);
});

test('una suscripción de Stripe activa figura como "paga"', function () {
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['source' => 'stripe', 'stripe_customer_id' => 'cus_1', 'cancel_at_period_end' => true]);

    $this->actingAs($user)->getJson('/api/billing')
        ->assertJsonPath('stripe.paying', true)->assertJsonPath('stripe.has_customer', true)->assertJsonPath('cancel_at_period_end', true);
});

test('todos los miembros ven la facturación, pero solo el propietario la gestiona', function () {
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);

    expect($this->actingAs($owner)->getJson('/api/billing')->json('can_manage'))->toBeTrue()
        ->and($this->actingAs($admin)->getJson('/api/billing')->json('can_manage'))->toBeFalse()
        ->and($this->actingAs($member)->getJson('/api/billing')->json('can_manage'))->toBeFalse();
});

test('un estado vencido o suspendido se informa con su motivo', function () {
    [$user, $organization] = userWithOrganization();
    $organization->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Uso abusivo'])->save();

    $this->actingAs($user)->getJson('/api/billing')->assertJsonPath('state', 'suspended')->assertJsonPath('suspension_reason', 'Uso abusivo');
});

test('la facturación exige sesión y empresa', function () {
    $this->getJson('/api/billing')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/billing')->assertNotFound();
});

// ---------- contratar un plan ----------

test('el propietario inicia el pago de un plan y recibe la URL de Stripe', function () {
    [$owner, $organization] = userWithOrganization();
    $stripe = fakeStripe();

    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])
        ->assertOk()->assertExactJson(['url' => 'https://checkout.example/business', 'via' => 'checkout']);

    expect($stripe->checkouts)->toHaveCount(1)
        ->and($stripe->checkouts[0]['organization']->id)->toBe($organization->id)
        ->and($stripe->checkouts[0]['plan']->slug)->toBe('business')
        ->and($stripe->checkouts[0]['user']->id)->toBe($owner->id)
        ->and($stripe->checkouts[0]['customerId'])->toBeNull();
});

test('si la empresa ya es cliente en Stripe se reutiliza su cliente', function () {
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'canceled', 'source' => 'stripe', 'stripe_customer_id' => 'cus_vuelve']);
    $stripe = fakeStripe();

    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])->assertOk()->assertJsonPath('via', 'checkout');

    expect($stripe->checkouts[0]['customerId'])->toBe('cus_vuelve');
});

test('quien ya paga cambia de plan en el portal, no creando una segunda suscripción', function () {
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'stripe', 'status' => 'active', 'stripe_customer_id' => 'cus_paga']);
    $stripe = fakeStripe();

    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])
        ->assertOk()->assertExactJson(['url' => 'https://portal.example/cus_paga', 'via' => 'portal']);

    expect($stripe->checkouts)->toBe([]);
});

test('solo el propietario puede contratar', function () {
    [, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    $stripe = fakeStripe();

    $this->actingAs($admin)->postJson('/api/billing/checkout', ['plan' => 'business'])->assertForbidden();

    expect($stripe->checkouts)->toBe([]);
});

test('validaciones al contratar', function () {
    [$owner] = userWithOrganization();
    fakeStripe();

    $this->actingAs($owner)->postJson('/api/billing/checkout', [])->assertJsonValidationErrors('plan');
    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'no-existe'])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertUnprocessable(); // sin precio en Stripe
    Plan::where('slug', 'business')->update(['is_active' => false]);
    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])->assertUnprocessable();
});

test('sin Stripe configurado se avisa con 503 y un código que el panel entiende', function () {
    [$owner] = userWithOrganization();
    fakeStripe(configured: false);

    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])->assertStatus(503)->assertJsonPath('code', 'billing_unavailable');
    $this->actingAs($owner)->postJson('/api/billing/portal')->assertUnprocessable();
});

test('si Stripe falla el cliente recibe un mensaje genérico y el detalle va al log', function () {
    [$owner] = userWithOrganization();
    fakeStripe(failWith: new RuntimeException('Invalid API Key provided: sk_live_SECRETO'));

    $response = $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'business'])->assertStatus(502);

    expect($response->getContent())->not->toContain('sk_live_SECRETO')->not->toContain('API Key');
});

// ---------- portal ----------

test('el portal exige ser propietario y tener un cliente en Stripe', function () {
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    $stripe = fakeStripe();

    $this->actingAs($owner)->postJson('/api/billing/portal')->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'suscripción de pago'));

    setPlan($organization, 'business', ['source' => 'stripe', 'stripe_customer_id' => 'cus_77']);
    $owner = $owner->fresh(); // el modelo en memoria conserva la suscripción vieja; en producción cada petición la carga de nuevo
    $this->actingAs($owner)->postJson('/api/billing/portal')->assertOk()->assertExactJson(['url' => 'https://portal.example/cus_77']);
    $this->actingAs($admin)->postJson('/api/billing/portal')->assertForbidden();

    expect($stripe->portals)->toBe(['cus_77']);
});

// ---------- planes públicos ----------

test('los planes públicos se ven sin sesión, ordenados, y no filtran IDs de Stripe', function () {
    Plan::where('slug', 'pro')->update(['is_active' => false]);

    $response = $this->getJson('/api/plans')->assertOk();

    expect(collect($response->json())->pluck('slug')->all())->toBe(['starter', 'business'])
        ->and($response->getContent())->not->toContain('price_starter')->not->toContain('stripe_price_id')
        ->and($response->json('0'))->toMatchArray(['slug' => 'starter', 'price_cents' => 1900, 'max_agents' => 1, 'max_monthly_messages' => 1000, 'purchasable' => true]);
});
