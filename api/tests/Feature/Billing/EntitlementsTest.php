<?php

use App\Enums\SubscriptionStatus;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Billing\BillingGate;
use App\Services\Billing\Entitlements;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

function entitlements(Organization $organization): Entitlements
{
    return app(BillingGate::class)->for($organization->fresh());
}

beforeEach(fn () => Carbon::setTestNow('2026-10-15 12:00:00 UTC'));
afterEach(fn () => Carbon::setTestNow());

test('una prueba vigente sirve con los topes del plan', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDays(5)]);

    $e = entitlements($organization);

    expect($e->state)->toBe('trialing')->and($e->serves())->toBeTrue()
        ->and($e->maxAgents())->toBe(1)->and($e->monthlyMessages())->toBe(1000)->and($e->maxSites())->toBe(1)
        ->and($e->hasFeature('human_inbox'))->toBeFalse();
});

test('una prueba vencida deja de servir', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->subDay()]);

    $e = entitlements($organization);

    expect($e->state)->toBe('expired')->and($e->serves())->toBeFalse()->and($e->maxAgents())->toBe(0)->and($e->hasFeature('human_inbox'))->toBeFalse();
});

test('estados de la suscripción: quién sirve y quién no', function (string $status, string $source, string $state, bool $serves) {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['status' => $status, 'source' => $source, 'current_period_end' => now()->addMonth()]);

    $e = entitlements($organization);

    expect($e->state)->toBe($state)->and($e->serves())->toBe($serves);
})->with([
    'activa por Stripe' => ['active', 'stripe', 'active', true],
    'cortesía activa' => ['active', 'manual', 'active', true],
    'cobro fallido (Stripe sigue reintentando)' => ['past_due', 'stripe', 'past_due', true],
    'impaga' => ['unpaid', 'stripe', 'unpaid', false],
    'cancelada' => ['canceled', 'stripe', 'canceled', false],
    'primer pago incompleto' => ['incomplete', 'stripe', 'incomplete', false],
]);

test('con cobro fallido se puede cortar el servicio por configuración', function () {
    config(['billing.past_due_keeps_service' => false]);
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['status' => 'past_due', 'source' => 'stripe']);

    expect(entitlements($organization)->serves())->toBeFalse();
});

test('una cortesía con fecha de fin vence; una de Stripe no se corta por fecha', function () {
    [, $manual] = userWithOrganization();
    setPlan($manual, 'pro', ['source' => 'manual', 'current_period_end' => now()->subDay()]);
    [, $stripe] = userWithOrganization();
    setPlan($stripe, 'pro', ['source' => 'stripe', 'current_period_end' => now()->subDay()]); // renovación aún no notificada

    expect(entitlements($manual)->state)->toBe('expired')->and(entitlements($stripe)->state)->toBe('active');
});

test('una empresa sin suscripción no tiene servicio', function () {
    [, $organization] = userWithOrganization();
    Subscription::where('organization_id', $organization->id)->delete();

    $e = entitlements($organization);

    expect($e->state)->toBe('none')->and($e->serves())->toBeFalse()->and($e->plan)->toBeNull()->and($e->monthlyMessages())->toBe(0);
});

test('una empresa suspendida no sirve aunque pague', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'pro', ['source' => 'stripe']);
    $organization->forceFill(['suspended_at' => now(), 'suspension_reason' => 'abuso'])->save();

    expect(entitlements($organization)->state)->toBe('suspended')->and(entitlements($organization)->serves())->toBeFalse();
});

test('el período de consumo: el de Stripe, el de la prueba, o el mes calendario para cortesías', function () {
    [, $stripe] = userWithOrganization();
    setPlan($stripe, 'pro', ['source' => 'stripe', 'current_period_start' => '2026-10-10 00:00:00', 'current_period_end' => '2026-11-10 00:00:00']);
    [, $trial] = userWithOrganization();
    setPlan($trial, 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => '2026-10-20 00:00:00', 'current_period_start' => '2026-10-06 00:00:00', 'current_period_end' => '2026-10-20 00:00:00']);
    [, $manual] = userWithOrganization();
    setPlan($manual, 'pro', ['source' => 'manual', 'current_period_start' => '2026-08-01 00:00:00', 'current_period_end' => '2027-02-01 00:00:00']);

    expect(entitlements($stripe)->periodStart->toDateString())->toBe('2026-10-10')
        ->and(entitlements($stripe)->periodEnd->toDateString())->toBe('2026-11-10')
        ->and(entitlements($trial)->periodStart->toDateString())->toBe('2026-10-06')
        // La fecha de una cortesía es de vencimiento, no de ciclo: el cupo sigue siendo mensual.
        ->and(entitlements($manual)->periodStart->toDateString())->toBe('2026-10-01')
        ->and(entitlements($manual)->periodEnd->toDateString())->toBe('2026-11-01');
});

test('si el período de Stripe ya pasó (renovación pendiente) se usa el mes calendario', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'pro', ['source' => 'stripe', 'current_period_start' => '2026-08-10 00:00:00', 'current_period_end' => '2026-09-10 00:00:00']);

    expect(entitlements($organization)->periodStart->toDateString())->toBe('2026-10-01');
});

test('el consumo cuenta solo respuestas a clientes del período y de la propia empresa', function () {
    [, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual']);
    usage($organization, 1);                                        // cuenta
    usage($organization, 1, 'widget', '2026-10-01 00:00:00');       // cuenta (límite inferior del mes)
    usage($organization, 1, 'widget', '2026-09-30 23:59:59');       // mes anterior
    usage($organization, 0, 'test');                                // prueba del panel: no cuenta
    usage($organization, 0, 'document', null, ['type' => 'embedding']);
    usage($other, 5);                                               // otra empresa

    expect(entitlements($organization)->messagesUsed())->toBe(2);
});

test('cupo agotado y porcentaje', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual']); // 1000 mensajes
    usage($organization, 250);
    expect(entitlements($organization)->usagePercent())->toBe(25)->and(entitlements($organization)->quotaExceeded())->toBeFalse();

    usage($organization, 750);
    expect(entitlements($organization)->usagePercent())->toBe(100)->and(entitlements($organization)->quotaExceeded())->toBeTrue();
});

test('con más agentes activos que el tope solo atienden los más antiguos', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['source' => 'manual']); // 3 agentes
    app(CurrentOrganization::class)->set($organization);
    $agents = collect(range(1, 5))->map(fn ($i) => Agent::factory()->create(['name' => "A$i"]));
    $inactive = Agent::factory()->create(['name' => 'Inactivo', 'is_active' => false]);
    $gate = app(BillingGate::class);
    $e = $gate->for($organization);

    $serving = $agents->map(fn (Agent $a) => $gate->agentServes($a, $e))->all();

    expect($serving)->toBe([true, true, true, false, false])
        ->and($gate->agentServes($inactive, $e))->toBeFalse();
});

test('el gate comparte el cálculo dentro de la misma petición', function () {
    [, $organization] = userWithOrganization();
    $gate = app(BillingGate::class);

    expect($gate->for($organization))->toBe($gate->for($organization));
    $gate->forget($organization);
    expect($gate->for($organization))->not->toBeNull();
});

test('SubscriptionStatus::fromStripe traduce los estados de Stripe', function () {
    expect(SubscriptionStatus::fromStripe('active'))->toBe(SubscriptionStatus::Active)
        ->and(SubscriptionStatus::fromStripe('trialing'))->toBe(SubscriptionStatus::Trialing)
        ->and(SubscriptionStatus::fromStripe('past_due'))->toBe(SubscriptionStatus::PastDue)
        ->and(SubscriptionStatus::fromStripe('unpaid'))->toBe(SubscriptionStatus::Unpaid)
        ->and(SubscriptionStatus::fromStripe('paused'))->toBe(SubscriptionStatus::Unpaid)
        ->and(SubscriptionStatus::fromStripe('canceled'))->toBe(SubscriptionStatus::Canceled)
        ->and(SubscriptionStatus::fromStripe('incomplete_expired'))->toBe(SubscriptionStatus::Canceled)
        ->and(SubscriptionStatus::fromStripe('incomplete'))->toBe(SubscriptionStatus::Incomplete)
        ->and(SubscriptionStatus::fromStripe('algo_nuevo'))->toBe(SubscriptionStatus::Incomplete);
});
