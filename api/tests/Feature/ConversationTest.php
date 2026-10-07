<?php

use App\Enums\OrganizationRole;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Str;

function conversationIn(Organization $organization, ?Agent $agent = null, array $attributes = [], int $messages = 2): Conversation
{
    app(CurrentOrganization::class)->set($organization);
    $agent ??= Agent::factory()->create(['name' => 'Sofía']);
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);

    $conversation = Conversation::factory()->create($attributes + [
        'agent_id' => $agent->id, 'agent_name' => $agent->name, 'contact_id' => $contact->id,
    ]);

    foreach (range(1, $messages) as $i) {
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => $i % 2 ? 'user' : 'assistant',
            'content' => $i % 2 ? "Pregunta $i" : "Respuesta $i",
            'model' => $i % 2 ? null : 'mock-1',
            'sources' => $i % 2 ? null : [['document_id' => 1, 'title' => 'Precios', 'similarity' => 0.5]],
        ]);
    }
    $conversation->forceFill(['message_count' => $messages])->save();

    return $conversation;
}

test('lista las conversaciones de la empresa, la más reciente primero, con vista previa', function () {
    [$user, $organization] = userWithOrganization();
    $old = conversationIn($organization, attributes: ['last_message_at' => now()->subDay()]);
    $new = conversationIn($organization, attributes: ['last_message_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/conversations')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$new->id, $old->id])
        ->and($response->json('data.0.contact.name'))->toStartWith('Visitante #')
        ->and($response->json('data.0.agent_name'))->toBe('Sofía')
        ->and($response->json('data.0.last_message'))->toBe(['role' => 'assistant', 'preview' => 'Respuesta 2'])
        ->and($response->json('data.0'))->not->toHaveKey('messages')
        ->and($response->json('meta.total'))->toBe(2);
});

test('no lista conversaciones de otras empresas', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    conversationIn($other);
    $mine = conversationIn($organization);

    $this->actingAs($user)->getJson('/api/conversations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
});

test('se puede filtrar por agente y paginar', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $a = Agent::factory()->create(['name' => 'A']);
    $b = Agent::factory()->create(['name' => 'B']);
    foreach (range(1, 25) as $i) {
        conversationIn($organization, $a, ['last_message_at' => now()->subMinutes($i)], messages: 1);
    }
    conversationIn($organization, $b, messages: 1);

    $this->actingAs($user)->getJson("/api/conversations?agent_id={$b->id}")->assertJsonCount(1, 'data');

    $page = $this->actingAs($user)->getJson("/api/conversations?agent_id={$a->id}")->assertOk();
    expect($page->json('data'))->toHaveCount(20)->and($page->json('meta.last_page'))->toBe(2);
});

test('el detalle trae los mensajes en orden con modelo, tokens y fuentes', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = conversationIn($organization, attributes: ['page_url' => 'https://tienda.com/servicios'], messages: 4);

    $response = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}")->assertOk();

    expect(array_column($response->json('messages'), 'role'))->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($response->json('messages.1.model'))->toBe('mock-1')
        ->and($response->json('messages.1.sources.0.title'))->toBe('Precios')
        ->and($response->json('page_url'))->toBe('https://tienda.com/servicios');
});

test('no se puede ver el detalle de una conversación de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    $foreign = conversationIn($other);

    $this->actingAs($user)->getJson("/api/conversations/{$foreign->id}")->assertNotFound();
});

test('un miembro con rol agente puede consultar el historial', function () {
    [, $organization] = userWithOrganization();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    $conversation = conversationIn($organization);

    $this->actingAs($member)->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($member)->getJson("/api/conversations/{$conversation->id}")->assertOk();
});

test('las conversaciones exigen sesión y empresa', function () {
    $this->getJson('/api/conversations')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/conversations')->assertNotFound();
});

test('al borrar el agente se conserva el historial con el nombre que tenía', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create(['name' => 'Valentina']);
    $conversation = conversationIn($organization, $agent);

    $this->actingAs($user)->deleteJson("/api/agents/{$agent->id}")->assertNoContent();

    $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()->assertJsonPath('agent_id', null)->assertJsonPath('agent_name', 'Valentina');
});
