<?php

use App\Enums\OrganizationRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingGate;
use App\Support\CurrentOrganization;

function planOrganization(string $plan, array $subscription = []): array
{
    [$user, $organization] = userWithOrganization();
    setPlan($organization, $plan, ['source' => 'manual', 'current_period_end' => null] + $subscription);

    return [$user, $organization];
}

function agentBody(array $overrides = []): array
{
    return array_merge(['name' => 'Sofía', 'tone' => 'friendly_professional', 'language' => 'es'], $overrides);
}

// ---------- tope de agentes ----------

test('Starter permite un solo agente activo y el segundo responde 402 con un mensaje claro', function () {
    [$user] = planOrganization('starter');

    $this->actingAs($user)->postJson('/api/agents', agentBody())->assertCreated();
    $response = $this->actingAs($user)->postJson('/api/agents', agentBody(['name' => 'Otra']))->assertStatus(402);

    expect($response->json('code'))->toBe('plan_limit')->and($response->json('reason'))->toBe('agents')
        ->and($response->json('message'))->toContain('1 agente')->toContain('sube de plan');
});

test('un agente inactivo no cuenta contra el tope, pero activarlo sí', function () {
    [$user] = planOrganization('starter');
    $first = $this->actingAs($user)->postJson('/api/agents', agentBody())->json('id');
    $second = $this->actingAs($user)->postJson('/api/agents', agentBody(['name' => 'Inactivo', 'is_active' => false]))->assertCreated()->json('id');

    $this->actingAs($user)->patchJson("/api/agents/{$second}", ['is_active' => true])->assertStatus(402);

    $this->actingAs($user)->patchJson("/api/agents/{$first}", ['is_active' => false])->assertOk();
    $this->actingAs($user)->patchJson("/api/agents/{$second}", ['is_active' => true])->assertOk();
});

test('editar un agente que ya está activo no choca con el tope', function () {
    [$user] = planOrganization('starter');
    $id = $this->actingAs($user)->postJson('/api/agents', agentBody())->json('id');

    $this->actingAs($user)->patchJson("/api/agents/{$id}", ['name' => 'Nuevo nombre', 'is_active' => true])->assertOk();
});

test('Business permite tres agentes activos', function () {
    [$user] = planOrganization('business');

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->postJson('/api/agents', agentBody(['name' => "A$i"]))->assertCreated();
    }
    $this->actingAs($user)->postJson('/api/agents', agentBody(['name' => 'A4']))->assertStatus(402);
});

test('con la prueba vencida no se puede crear un agente ni probarlo', function () {
    [$user, $organization] = planOrganization('starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->subDay()]);
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create();

    $create = $this->actingAs($user)->postJson('/api/agents', agentBody())->assertStatus(402);
    expect($create->json('message'))->toContain('período de prueba terminó');

    $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", ['messages' => [['role' => 'user', 'content' => 'hola']]])
        ->assertStatus(402)->assertJsonPath('code', 'plan_limit');

    // Los datos siguen siendo consultables.
    $this->actingAs($user)->getJson('/api/agents')->assertOk()->assertJsonCount(1);
});

test('una empresa suspendida tampoco crea agentes', function () {
    [$user, $organization] = planOrganization('pro');
    $organization->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($user)->postJson('/api/agents', agentBody())->assertStatus(402)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'suspendida'));
});

// ---------- atención humana: función de Business ----------

test('en Starter no se puede tomar, responder ni devolver a la IA, pero sí cerrar', function () {
    [$user, $organization] = planOrganization('starter');
    $conversation = inboxConversation($organization, 'waiting');

    foreach (['take', 'release'] as $action) {
        $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/{$action}")
            ->assertStatus(402)->assertJsonPath('reason', 'human_inbox');
    }
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hola'])->assertStatus(402);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/close")->assertOk()->assertJsonPath('status', 'closed');
});

test('en Business sí hay atención humana', function () {
    [$user, $organization] = planOrganization('business');
    $conversation = inboxConversation($organization, 'waiting');

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take")->assertOk();
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'hola'])->assertCreated();
});

test('sin atención humana, si el agente pide pasar a una persona la conversación sigue con la IA y el lead se conserva', function () {
    [, $organization] = planOrganization('starter');
    $agent = widgetAgent($organization);
    $ai = fakeAI(reply: [
        'reply' => 'Un asesor te escribirá.', 'action' => 'request_human',
        'lead' => ['name' => 'Ana', 'phone' => '3001234567', 'email' => null, 'interest' => 'Plan Pro'],
    ]);

    $response = sendWidget($agent, 'quiero hablar con alguien')->assertCreated();

    expect($response->json('status'))->toBe('ai')
        ->and(Conversation::withoutGlobalScopes()->first()->status->value)->toBe('ai')
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1)
        ->and($ai->calls[0]['system'])->toContain('esta empresa no atiende personas en el chat')
        ->and($ai->calls[0]['system'])->not->toContain('action = "request_human" cuando');
});

test('con atención humana el prompt le dice al agente que puede transferir', function () {
    [, $organization] = planOrganization('business');
    $agent = widgetAgent($organization);
    $ai = fakeAI(reply: ['reply' => 'Claro', 'action' => 'request_human', 'lead' => null]);

    $response = sendWidget($agent, 'una persona por favor')->assertCreated();

    expect($response->json('status'))->toBe('waiting')
        ->and($ai->calls[0]['system'])->toContain('action = "request_human" cuando')->not->toContain('no atiende personas');
});

test('la prueba del panel refleja lo mismo: en Starter no anuncia traspaso', function () {
    [$user, $organization] = planOrganization('starter');
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create();
    fakeAI(reply: ['reply' => 'Te paso', 'action' => 'request_human', 'lead' => null]);

    $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", ['messages' => [['role' => 'user', 'content' => 'una persona']]])
        ->assertOk()->assertJsonPath('action', 'none');
});

// ---------- personalización del widget ----------

test('personalizar el widget es de Business: en Starter se rechaza, en Business se acepta', function () {
    [$starter, $starterOrg] = planOrganization('starter');
    [$business, $businessOrg] = planOrganization('business');
    $a = createAgentIn($starterOrg);
    $b = createAgentIn($businessOrg);

    $response = $this->actingAs($starter)->patchJson("/api/agents/{$a->id}", ['widget_color' => '#112233'])->assertUnprocessable();
    expect($response->json('errors.widget_color.0'))->toContain('plan Business');
    $this->actingAs($starter)->patchJson("/api/agents/{$a->id}", ['widget_position' => 'left'])->assertJsonValidationErrors('widget_position');
    $this->actingAs($starter)->patchJson("/api/agents/{$a->id}", ['name' => 'Se puede editar lo demás'])->assertOk();

    $this->actingAs($business)->patchJson("/api/agents/{$b->id}", ['widget_color' => '#112233', 'widget_position' => 'left'])->assertOk();
});

// ---------- prueba gratuita al crear la empresa ----------

test('al crear la empresa empieza una prueba gratuita del plan Starter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/organization', ['name' => 'Mi negocio'])->assertCreated();

    $subscription = Organization::where('name', 'Mi negocio')->first()->subscription;
    expect($subscription->status->value)->toBe('trialing')
        ->and($subscription->source)->toBe('trial')
        ->and($subscription->plan->slug)->toBe('starter')
        ->and((int) round(now()->diffInDays($subscription->trial_ends_at, true)))->toBe(14);
});

test('los días de prueba son configurables', function () {
    config(['billing.trial_days' => 30]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/organization', ['name' => 'Otro negocio'])->assertCreated();

    expect((int) round(now()->diffInDays(Organization::where('name', 'Otro negocio')->first()->subscription->trial_ends_at, true)))->toBe(30);
});

test('la prueba arranca con un agente y con la atención humana apagada, pero ya sirve', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/organization', ['name' => 'Negocio nuevo'])->assertCreated();
    $organization = Organization::where('name', 'Negocio nuevo')->first();

    $this->actingAs($user)->postJson('/api/agents', agentBody())->assertCreated();
    $this->actingAs($user)->postJson('/api/agents', agentBody(['name' => 'Segundo']))->assertStatus(402);
    expect(app(BillingGate::class)->for($organization)->hasFeature('human_inbox'))->toBeFalse();
});

test('un miembro con rol agente también ve el límite al intentar crear (y antes, su falta de permiso)', function () {
    [, $organization] = planOrganization('starter');
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);

    $this->actingAs($member)->postJson('/api/agents', agentBody())->assertForbidden();
});
