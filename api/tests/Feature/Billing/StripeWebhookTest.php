<?php

use App\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Notifications\PaymentFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

const WHSEC = 'whsec_test_secret';

beforeEach(function () {
    config(['billing.stripe.webhook_secret' => WHSEC]);
    Plan::where('slug', 'starter')->update(['stripe_price_id' => 'price_starter']);
    Plan::where('slug', 'business')->update(['stripe_price_id' => 'price_business']);
    Plan::where('slug', 'pro')->update(['stripe_price_id' => 'price_pro']);
});

function stripeSubscription(Organization $organization, array $overrides = []): array
{
    return $overrides + [
        'id' => 'sub_123', 'object' => 'subscription', 'customer' => 'cus_123', 'status' => 'active',
        'cancel_at_period_end' => false, 'cancel_at' => null, 'canceled_at' => null, 'trial_end' => null,
        'metadata' => ['organization_id' => (string) $organization->id],
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1', 'price' => ['id' => 'price_business'],
            'current_period_start' => 1_792_000_000, 'current_period_end' => 1_794_592_000,
        ]]],
    ];
}

function stripePayload(string $type, array $object, ?string $id = null, ?int $created = null): string
{
    return json_encode([
        'id' => $id ?? 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type,
        'created' => $created ?? 1_792_100_000, 'api_version' => '2026-09-30.endive',
        'data' => ['object' => $object],
    ]);
}

function signedStripe(string $payload, ?string $secret = WHSEC, ?int $timestamp = null): array
{
    $timestamp ??= time();

    return ['Stripe-Signature' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? WHSEC)];
}

function sendStripe(string $payload, ?array $headers = null)
{
    // postJson recodificaría el cuerpo: la firma se calcula sobre el texto exacto, así que se envía crudo.
    return test()->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ...collect($headers ?? signedStripe($payload))->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(),
    ], $payload);
}

// ---------- firma ----------

test('rechaza un webhook sin firma, con firma de otro secreto o con el cuerpo alterado', function () {
    [, $organization] = userWithOrganization();
    $payload = stripePayload('customer.subscription.updated', stripeSubscription($organization));

    sendStripe($payload, [])->assertStatus(400);
    sendStripe($payload, signedStripe($payload, 'whsec_otro'))->assertStatus(400);
    sendStripe($payload.' ', signedStripe($payload))->assertStatus(400);   // alterado después de firmar
    sendStripe('{"no es": "un evento"', signedStripe('{"no es": "un evento"'))->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
});

test('rechaza una firma con marca de tiempo vieja (reenvío malicioso)', function () {
    [, $organization] = userWithOrganization();
    $payload = stripePayload('customer.subscription.updated', stripeSubscription($organization));

    sendStripe($payload, signedStripe($payload, WHSEC, time() - 3600))->assertStatus(400);
});

test('sin el secreto configurado responde 503 en vez de aceptar a ciegas', function () {
    config(['billing.stripe.webhook_secret' => null]);
    [, $organization] = userWithOrganization();
    $payload = stripePayload('customer.subscription.updated', stripeSubscription($organization));

    sendStripe($payload, signedStripe($payload))->assertStatus(503);
});

test('el webhook no necesita sesión ni CSRF', function () {
    [, $organization] = userWithOrganization();
    $payload = stripePayload('customer.subscription.created', stripeSubscription($organization));

    sendStripe($payload)->assertOk()->assertJson(['received' => true]);
});

// ---------- suscripción ----------

test('una suscripción nueva reemplaza la prueba con el plan, el estado y el período de Stripe', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDays(3)]);

    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization)))->assertOk();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->plan->slug)->toBe('business')
        ->and($subscription->status->value)->toBe('active')
        ->and($subscription->source)->toBe('stripe')
        ->and($subscription->stripe_customer_id)->toBe('cus_123')
        ->and($subscription->stripe_subscription_id)->toBe('sub_123')
        ->and($subscription->current_period_start->timestamp)->toBe(1_792_000_000)
        ->and($subscription->current_period_end->timestamp)->toBe(1_794_592_000)
        ->and($subscription->cancel_at_period_end)->toBeFalse();

    expect(AuditLog::where('action', 'subscription.synced')->where('organization_id', $organization->id)->count())->toBe(1);
});

test('crea la suscripción aunque la empresa no tuviera ninguna', function () {
    [, $organization] = userWithOrganization();
    Subscription::where('organization_id', $organization->id)->delete();

    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization, ['items' => ['data' => [[
        'price' => ['id' => 'price_pro'], 'current_period_start' => 1_792_000_000, 'current_period_end' => 1_794_592_000,
    ]]]])))->assertOk();

    expect($organization->fresh()->subscription->plan->slug)->toBe('pro');
});

test('un cambio de plan, de estado o una cancelación programada se reflejan', function () {
    [, $organization] = userWithOrganization();
    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization), 'evt_1', 1_792_100_000));

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, [
        'status' => 'past_due', 'cancel_at_period_end' => true,
        'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_start' => 1_792_000_000, 'current_period_end' => 1_794_592_000]]],
    ]), 'evt_2', 1_792_200_000))->assertOk();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->plan->slug)->toBe('pro')->and($subscription->status->value)->toBe('past_due')->and($subscription->cancel_at_period_end)->toBeTrue();
});

test('una cancelación con fecha ("cancel_at") también cuenta como cancelación programada', function () {
    [, $organization] = userWithOrganization();

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, ['cancel_at' => 1_794_592_000])));

    expect($organization->fresh()->subscription->cancel_at_period_end)->toBeTrue();
});

test('al borrarse la suscripción queda cancelada y la empresa sin servicio', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization), 'evt_1', 1_792_100_000));
    expect(test()->getJson("/api/widget/{$agent->public_id}/config")->json('active'))->toBeTrue();

    sendStripe(stripePayload('customer.subscription.deleted', stripeSubscription($organization, ['canceled_at' => 1_792_300_000]), 'evt_2', 1_792_300_000))->assertOk();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->status->value)->toBe('canceled')->and($subscription->canceled_at)->not->toBeNull();
    app()->forgetScopedInstances();
    expect(test()->getJson("/api/widget/{$agent->public_id}/config")->json('active'))->toBeFalse();
});

test('lee el período también de la suscripción (versiones antiguas de la API de Stripe)', function () {
    [, $organization] = userWithOrganization();

    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization, [
        'current_period_start' => 1_700_000_000, 'current_period_end' => 1_702_592_000,
        'items' => ['data' => [['price' => ['id' => 'price_business']]]],
    ])));

    expect($organization->fresh()->subscription->current_period_end->timestamp)->toBe(1_702_592_000);
});

// ---------- idempotencia y orden ----------

test('un evento repetido se procesa una sola vez', function () {
    [, $organization] = userWithOrganization();
    $payload = stripePayload('customer.subscription.created', stripeSubscription($organization), 'evt_repetido');

    sendStripe($payload)->assertOk();
    $firstAudit = AuditLog::where('action', 'subscription.synced')->count();
    sendStripe($payload)->assertOk();
    sendStripe($payload)->assertOk();

    expect(StripeEvent::where('event_id', 'evt_repetido')->count())->toBe(1)
        ->and(AuditLog::where('action', 'subscription.synced')->count())->toBe($firstAudit);
});

test('un evento viejo que llega tarde no pisa uno más reciente', function () {
    [, $organization] = userWithOrganization();

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, ['status' => 'canceled']), 'evt_nuevo', 1_792_500_000));
    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, ['status' => 'active']), 'evt_viejo', 1_792_400_000))->assertOk();

    expect($organization->fresh()->subscription->status->value)->toBe('canceled');
});

test('eventos del mismo segundo se aplican en el orden de llegada', function () {
    [, $organization] = userWithOrganization();

    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization, ['status' => 'incomplete']), 'evt_a', 1_792_100_000));
    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, ['status' => 'active']), 'evt_b', 1_792_100_000));

    expect($organization->fresh()->subscription->status->value)->toBe('active');
});

// ---------- cómo se reconoce la empresa ----------

test('sin metadata se reconoce la empresa por el ID de la suscripción o del cliente', function (string $by) {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'stripe', 'stripe_subscription_id' => 'sub_conocida', 'stripe_customer_id' => 'cus_conocido']);
    $overrides = ['metadata' => [], 'status' => 'past_due'] + ($by === 'subscription' ? ['id' => 'sub_conocida', 'customer' => 'cus_otro'] : ['id' => 'sub_nueva', 'customer' => 'cus_conocido']);

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, $overrides)))->assertOk();

    expect($organization->fresh()->subscription->status->value)->toBe('past_due');
})->with(['subscription', 'customer']);

test('un evento de una empresa desconocida se acepta pero no cambia nada', function () {
    [, $organization] = userWithOrganization();
    $before = $organization->fresh()->subscription->only(['status', 'plan_id']);

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, ['metadata' => ['organization_id' => '999999'], 'id' => 'sub_x', 'customer' => 'cus_x'])))->assertOk();

    expect($organization->fresh()->subscription->only(['status', 'plan_id']))->toEqual($before);
});

test('un precio sin plan asociado conserva el plan actual y solo actualiza el estado', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['source' => 'stripe']);

    sendStripe(stripePayload('customer.subscription.updated', stripeSubscription($organization, [
        'status' => 'past_due', 'items' => ['data' => [['price' => ['id' => 'price_desconocido'], 'current_period_end' => 1_794_592_000]]],
    ])))->assertOk();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->plan->slug)->toBe('business')->and($subscription->status->value)->toBe('past_due');
});

test('un precio sin plan y sin suscripción previa no inventa un plan y deja rastro', function () {
    [, $organization] = userWithOrganization();
    Subscription::where('organization_id', $organization->id)->delete();

    sendStripe(stripePayload('customer.subscription.created', stripeSubscription($organization, [
        'items' => ['data' => [['price' => ['id' => 'price_desconocido']]]],
    ])))->assertOk();

    expect($organization->fresh()->subscription)->toBeNull()
        ->and(AuditLog::where('action', 'stripe.unknown_price')->count())->toBe(1);
});

// ---------- checkout y pagos ----------

test('al completar el checkout se enlazan el cliente y la suscripción de Stripe', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDays(3)]);

    sendStripe(stripePayload('checkout.session.completed', [
        'id' => 'cs_1', 'mode' => 'subscription', 'client_reference_id' => (string) $organization->id, 'customer' => 'cus_999', 'subscription' => 'sub_999',
    ]))->assertOk();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->stripe_customer_id)->toBe('cus_999')->and($subscription->stripe_subscription_id)->toBe('sub_999');
});

test('un checkout que no es de suscripción se ignora', function () {
    [, $organization] = userWithOrganization();

    sendStripe(stripePayload('checkout.session.completed', ['id' => 'cs_2', 'mode' => 'payment', 'client_reference_id' => (string) $organization->id, 'customer' => 'cus_pago']))->assertOk();

    expect($organization->fresh()->subscription->stripe_customer_id)->toBeNull();
});

test('un cobro fallido avisa a propietario y administradores', function () {
    Notification::fake();
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    setPlan($organization, 'business', ['source' => 'stripe', 'stripe_customer_id' => 'cus_pago']);

    sendStripe(stripePayload('invoice.payment_failed', ['id' => 'in_1', 'customer' => 'cus_pago']))->assertOk();

    Notification::assertSentTo([$owner, $admin], PaymentFailed::class);
    Notification::assertNotSentTo($member, PaymentFailed::class);
    expect(AuditLog::where('action', 'stripe.payment_failed')->count())->toBe(1);
});

test('el correo de cobro fallido manda al portal de facturación', function () {
    [$owner] = userWithOrganization();

    $mail = (new PaymentFailed('Barbería XYZ'))->toMail($owner);

    expect($mail->subject)->toBe('No pudimos cobrar tu suscripción')
        ->and(implode(' ', $mail->introLines))->toContain('Barbería XYZ')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url').'/billing');
});

test('un cobro fallido de un cliente desconocido se ignora', function () {
    Notification::fake();

    sendStripe(stripePayload('invoice.payment_failed', ['id' => 'in_2', 'customer' => 'cus_nadie']))->assertOk();

    Notification::assertNothingSent();
});

test('los eventos que no interesan se aceptan y se registran', function () {
    sendStripe(stripePayload('customer.created', ['id' => 'cus_1'], 'evt_ignorado'))->assertOk();

    expect(StripeEvent::where('event_id', 'evt_ignorado')->exists())->toBeTrue();
});
