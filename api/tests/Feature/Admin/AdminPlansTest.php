<?php

use App\Models\AuditLog;
use App\Models\Plan;
use App\Services\Billing\BillingGate;

test('lista todos los planes (también los inactivos) con su ID de precio y cuántas empresas los usan', function () {
    Plan::where('slug', 'pro')->update(['is_active' => false, 'stripe_price_id' => 'price_pro']);
    [, $a] = userWithOrganization();
    [, $b] = userWithOrganization();
    setPlan($a, 'business', ['source' => 'manual']);
    setPlan($b, 'business', ['source' => 'manual']);

    $plans = collect($this->actingAs(superadmin())->getJson('/api/admin/plans')->assertOk()->json())->keyBy('slug');

    expect($plans->keys()->all())->toBe(['starter', 'business', 'pro'])
        ->and($plans['pro']['is_active'])->toBeFalse()->and($plans['pro']['stripe_price_id'])->toBe('price_pro')
        ->and($plans['business']['subscribers'])->toBe(2)
        ->and($plans['starter']['features'])->toBe(['human_inbox' => false, 'widget_customization' => false, 'analytics' => false])
        ->and($plans->pluck('max_users')->all())->toBe([1, 3, 10]);
});

test('se edita el precio, los topes y las funciones de un plan, con auditoría de qué cambió', function () {
    $plan = Plan::where('slug', 'starter')->first();
    $admin = superadmin();

    $response = $this->actingAs($admin)->patchJson("/api/admin/plans/{$plan->id}", [
        'price_cents' => 2400, 'max_monthly_messages' => 1500, 'max_agents' => 2, 'max_sites' => null, 'max_users' => 2, 'features' => ['human_inbox' => true, 'analytics' => true],
    ])->assertOk();

    expect($response->json('price_cents'))->toBe(2400)->and($response->json('max_sites'))->toBeNull()
        ->and($response->json('max_users'))->toBe(2)
        ->and($response->json('features'))->toBe(['human_inbox' => true, 'widget_customization' => false, 'analytics' => true]); // una función no mencionada se conserva

    $log = AuditLog::where('action', 'plan.updated')->first();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->metadata['plan'])->toBe('starter')
        ->and($log->metadata['changes']['price_cents'])->toEqual(['from' => 1900, 'to' => 2400]) // jsonb no conserva el orden de las claves
        ->and($log->metadata['changes']['max_monthly_messages'])->toEqual(['from' => 1000, 'to' => 1500]);
});

test('un cambio de plan se aplica de inmediato a las empresas que lo usan', function () {
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual', 'current_period_end' => null]);
    $plan = Plan::where('slug', 'starter')->first();
    $this->actingAs($user)->postJson('/api/agents', ['name' => 'Uno', 'tone' => 'formal', 'language' => 'es'])->assertCreated();
    $this->actingAs($user)->postJson('/api/agents', ['name' => 'Dos', 'tone' => 'formal', 'language' => 'es'])->assertStatus(402);

    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$plan->id}", ['max_agents' => 2])->assertOk();

    resetRequestState();
    $this->actingAs($user)->postJson('/api/agents', ['name' => 'Dos', 'tone' => 'formal', 'language' => 'es'])->assertCreated();
});

test('no registra auditoría si no cambió nada', function () {
    $plan = Plan::where('slug', 'starter')->first();

    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$plan->id}", ['price_cents' => $plan->price_cents, 'name' => $plan->name])->assertOk();

    expect(AuditLog::where('action', 'plan.updated')->count())->toBe(0);
});

test('se desactiva un plan: deja de ofrecerse pero sus suscriptores siguen', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['source' => 'manual', 'current_period_end' => null]);
    $plan = Plan::where('slug', 'business')->first();

    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$plan->id}", ['is_active' => false])->assertOk();

    expect(collect($this->getJson('/api/plans')->json())->pluck('slug')->all())->toBe(['starter', 'pro']);
    app()->forgetScopedInstances();
    expect(app(BillingGate::class)->for($organization)->serves())->toBeTrue();
});

test('el ID de precio de Stripe no se puede repetir entre planes', function () {
    Plan::where('slug', 'business')->update(['stripe_price_id' => 'price_dup']);
    $starter = Plan::where('slug', 'starter')->first();

    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$starter->id}", ['stripe_price_id' => 'price_dup'])->assertJsonValidationErrors('stripe_price_id');
    // Sí puede conservar el suyo (ignora su propia fila) y quitarlo.
    $business = Plan::where('slug', 'business')->first();
    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$business->id}", ['stripe_price_id' => 'price_dup', 'name' => 'Business+'])->assertOk();
    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$business->id}", ['stripe_price_id' => null])->assertOk()->assertJsonPath('stripe_price_id', null);
});

test('valida los valores de un plan', function (array $payload, string $field) {
    $plan = Plan::where('slug', 'starter')->first();

    $this->actingAs(superadmin())->patchJson("/api/admin/plans/{$plan->id}", $payload)->assertJsonValidationErrors($field);
})->with([
    'precio negativo' => [['price_cents' => -1], 'price_cents'],
    'precio absurdo' => [['price_cents' => 99999999], 'price_cents'],
    'cero agentes' => [['max_agents' => 0], 'max_agents'],
    'cero mensajes' => [['max_monthly_messages' => 0], 'max_monthly_messages'],
    'función desconocida' => [['features' => ['api_gratis' => true]], 'features'],
    'función no booleana' => [['features' => ['human_inbox' => 'quizás']], 'features.human_inbox'],
    'nombre vacío' => [['name' => ''], 'name'],
]);

test('un plan inexistente responde 404', function () {
    $this->actingAs(superadmin())->patchJson('/api/admin/plans/999999', ['price_cents' => 1])->assertNotFound();
});
