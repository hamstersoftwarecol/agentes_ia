<?php

use App\Enums\OrganizationRole;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;

function agentPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sofía',
        'role' => 'Asesora comercial',
        'goal' => 'Ayudar a clientes interesados en nuestros servicios.',
        'tone' => 'friendly_professional',
        'language' => 'es',
        'instructions' => "- No inventar precios.\n- Transferir a una persona cuando no pueda responder.",
        'welcome_message' => '¡Hola! Soy Sofía, ¿en qué te ayudo?',
    ], $overrides);
}

test('se puede crear un agente y queda activo con un identificador público', function () {
    [$user, $organization] = userWithOrganization();

    $response = $this->actingAs($user)->postJson('/api/agents', agentPayload())
        ->assertCreated()
        ->assertJsonPath('name', 'Sofía')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('tone', 'friendly_professional');

    expect($response->json('public_id'))->toMatch('/^ag_[A-Za-z0-9]{10}$/');
    $this->assertDatabaseHas('agents', ['organization_id' => $organization->id, 'name' => 'Sofía']);
});

test('valida los campos obligatorios, el tono y el idioma', function () {
    [$user] = userWithOrganization();

    $this->actingAs($user)->postJson('/api/agents', ['name' => '', 'tone' => 'agresivo', 'language' => 'klingon'])
        ->assertJsonValidationErrors(['name', 'tone', 'language']);
});

test('lista solo los agentes de la empresa actual', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    createAgentIn($organization, ['name' => 'Mío']);
    createAgentIn($other, ['name' => 'Ajeno']);

    $this->actingAs($user)->getJson('/api/agents')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Mío');
});

test('no se puede ver, editar ni borrar el agente de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    $foreign = createAgentIn($other);

    $this->actingAs($user)->getJson("/api/agents/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->patchJson("/api/agents/{$foreign->id}", ['name' => 'Hack'])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/agents/{$foreign->id}")->assertNotFound();
    $this->assertDatabaseHas('agents', ['id' => $foreign->id, 'name' => $foreign->name]);
});

test('se puede editar un agente', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization);

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", agentPayload(['name' => 'Valentina', 'tone' => 'casual', 'language' => 'en']))
        ->assertOk()
        ->assertJsonPath('name', 'Valentina')
        ->assertJsonPath('tone', 'casual')
        ->assertJsonPath('language', 'en');
});

test('se puede activar y desactivar un agente sin reenviar el resto de datos', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization, ['name' => 'Sofía']);

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('name', 'Sofía');

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['is_active' => true])->assertJsonPath('is_active', true);
});

test('se puede borrar un agente', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization);

    $this->actingAs($user)->deleteJson("/api/agents/{$agent->id}")->assertNoContent();
    $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
});

test('puede asignarse una base de conocimiento de la misma empresa', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $kb = KnowledgeBase::factory()->create(['name' => 'Servicios']);

    $this->actingAs($user)->postJson('/api/agents', agentPayload(['knowledge_base_id' => $kb->id]))
        ->assertCreated()
        ->assertJsonPath('knowledge_base.name', 'Servicios');
});

test('no puede asignarse una base de conocimiento de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    app(CurrentOrganization::class)->set($other);
    $foreignKb = KnowledgeBase::factory()->create();

    $this->actingAs($user)->postJson('/api/agents', agentPayload(['knowledge_base_id' => $foreignKb->id]))
        ->assertJsonValidationErrors('knowledge_base_id');

    $agent = createAgentIn(Organization::find($user->current_organization_id));
    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['knowledge_base_id' => $foreignKb->id])
        ->assertJsonValidationErrors('knowledge_base_id');
});

test('un miembro con rol agente puede ver pero no crear, editar ni borrar', function () {
    [$owner, $organization] = userWithOrganization();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    $agent = createAgentIn($organization);

    $this->actingAs($member)->getJson('/api/agents')->assertOk()->assertJsonCount(1);
    $this->actingAs($member)->getJson("/api/agents/{$agent->id}")->assertOk();
    $this->actingAs($member)->postJson('/api/agents', agentPayload())->assertForbidden();
    $this->actingAs($member)->patchJson("/api/agents/{$agent->id}", ['is_active' => false])->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/agents/{$agent->id}")->assertForbidden();
});

test('los endpoints de agentes exigen sesión y empresa', function () {
    $this->getJson('/api/agents')->assertUnauthorized();

    $this->actingAs(User::factory()->create())->getJson('/api/agents')->assertNotFound();
});

test('el route model binding usa la empresa del usuario aunque no hubiera ninguna fijada antes', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization);

    // Estado limpio, como al empezar una petición real (o un worker de Octane).
    app(CurrentOrganization::class)->set(null);

    $this->actingAs($user)->getJson("/api/agents/{$agent->id}")->assertOk()->assertJsonPath('id', $agent->id);
});

test('el route model binding ignora la empresa que haya quedado fijada de una petición anterior', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    $mine = createAgentIn($organization);
    $foreign = createAgentIn($other); // deja a $other como empresa fijada

    $this->actingAs($user)->getJson("/api/agents/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->getJson("/api/agents/{$mine->id}")->assertOk();
});

test('el widget arranca a la derecha con el color de marca y se puede personalizar', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization);

    $this->actingAs($user)->getJson("/api/agents/{$agent->id}")
        ->assertJsonPath('widget_position', 'right')->assertJsonPath('widget_color', '#b45309');

    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['widget_position' => 'left', 'widget_color' => '#0A66C2'])
        ->assertOk()->assertJsonPath('widget_position', 'left')->assertJsonPath('widget_color', '#0A66C2');
});

test('rechaza posiciones y colores de widget inválidos (el color acaba inyectado en CSS)', function () {
    [$user, $organization] = userWithOrganization();
    $agent = createAgentIn($organization);

    foreach (['red', '#fff', '#12345g', 'url(javascript:alert(1))', '#123456; background:red', '123456'] as $bad) {
        $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['widget_color' => $bad])
            ->assertJsonValidationErrors('widget_color');
    }
    $this->actingAs($user)->patchJson("/api/agents/{$agent->id}", ['widget_position' => 'center'])
        ->assertJsonValidationErrors('widget_position');
});
