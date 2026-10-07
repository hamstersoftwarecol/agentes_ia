<?php

use App\Enums\OrganizationRole;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;

// ---------- tomar ----------

test('tomar una conversación en espera la asigna a quien la toma', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'waiting');

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take")
        ->assertOk()
        ->assertJsonPath('status', 'human')
        ->assertJsonPath('assigned_to.id', $user->id)
        ->assertJsonPath('assigned_to.name', $user->name);

    expect($conversation->fresh()->assigned_user_id)->toBe($user->id);
});

test('también se puede intervenir mientras todavía atiende la IA', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'ai');

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take")->assertOk()->assertJsonPath('status', 'human');
});

test('tomar una conversación cerrada la reabre', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'closed', ['closed_at' => now()]);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take")->assertOk()->assertJsonPath('status', 'human');

    expect($conversation->fresh()->closed_at)->toBeNull();
});

test('otro miembro del equipo puede tomarla de quien la tenía', function () {
    [$first, $organization] = userWithOrganization();
    [$second] = userWithOrganization(OrganizationRole::Agent, $organization);
    $conversation = inboxConversation($organization, 'waiting');
    $this->actingAs($first)->postJson("/api/conversations/{$conversation->id}/take");

    $this->actingAs($second)->postJson("/api/conversations/{$conversation->id}/take")->assertOk()->assertJsonPath('assigned_to.id', $second->id);
});

// ---------- responder ----------

test('responder guarda el mensaje con el nombre de pila, toma la conversación y no llama a la IA', function () {
    [$user, $organization] = userWithOrganization();
    $user->forceFill(['name' => 'María José Torres'])->save();
    $conversation = inboxConversation($organization, 'waiting');
    $ai = fakeAI();

    $response = $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => "  Hola, soy María.  \n"])->assertCreated();

    $human = Message::withoutGlobalScopes()->where('role', 'human')->first();
    expect($human->content)->toBe('Hola, soy María.') // exactamente lo escrito, recortado
        ->and($human->sender_name)->toBe('María')
        ->and($human->user_id)->toBe($user->id)
        ->and($response->json('status'))->toBe('human')
        ->and($response->json('assigned_to.id'))->toBe($user->id)
        ->and(collect($response->json('messages'))->last()['sender_name'])->toBe('María')
        ->and($conversation->fresh()->message_count)->toBe(2)
        ->and($ai->calls)->toBe([]);
});

test('responder en una conversación cerrada la reabre', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'closed', ['closed_at' => now()]);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Seguimiento'])
        ->assertCreated()->assertJsonPath('status', 'human');

    expect($conversation->fresh()->closed_at)->toBeNull();
});

test('un compañero puede responder sin quitarle la conversación a quien la atiende', function () {
    [$owner, $organization] = userWithOrganization();
    [$colleague] = userWithOrganization(OrganizationRole::Agent, $organization);
    $conversation = inboxConversation($organization, 'waiting');
    $this->actingAs($owner)->postJson("/api/conversations/{$conversation->id}/take");

    $this->actingAs($colleague)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Yo me encargo de esto'])
        ->assertCreated()->assertJsonPath('assigned_to.id', $owner->id);
});

test('valida el contenido de la respuesta', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'waiting');
    $url = "/api/conversations/{$conversation->id}/messages";

    $this->actingAs($user)->postJson($url, [])->assertJsonValidationErrors('content');
    $this->actingAs($user)->postJson($url, ['content' => '   '])->assertJsonValidationErrors('content');
    $this->actingAs($user)->postJson($url, ['content' => str_repeat('a', 2001)])->assertJsonValidationErrors('content');
    $this->actingAs($user)->postJson($url, ['content' => ['x']])->assertJsonValidationErrors('content');

    expect(Message::withoutGlobalScopes()->where('role', 'human')->count())->toBe(0);
});

// ---------- devolver a la IA / cerrar ----------

test('devolver a la IA limpia al responsable', function (string $from) {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, $from);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take");
    if ($from === 'waiting') {
        $conversation->forceFill(['status' => 'waiting', 'assigned_user_id' => null])->save();
    }

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/release")
        ->assertOk()->assertJsonPath('status', 'ai')->assertJsonPath('assigned_to', null);

    expect($conversation->fresh()->handoff_at)->toBeNull();
})->with(['atendida por una persona' => ['ai'], 'en espera' => ['waiting']]);

test('no se puede devolver a la IA lo que ya atiende la IA ni lo cerrado', function () {
    [$user, $organization] = userWithOrganization();
    $ai = inboxConversation($organization, 'ai');
    $closed = inboxConversation($organization, 'closed');

    $this->actingAs($user)->postJson("/api/conversations/{$ai->id}/release")->assertStatus(409);
    $this->actingAs($user)->postJson("/api/conversations/{$closed->id}/release")->assertStatus(409);
});

test('cerrar marca la conversación como resuelta', function (string $from) {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, $from);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/close")->assertOk()->assertJsonPath('status', 'closed');

    expect($conversation->fresh()->closed_at)->not->toBeNull();
})->with(['ai', 'waiting', 'human']);

test('no se puede cerrar dos veces', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'closed');

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/close")->assertStatus(409);
});

// ---------- listado y detalle ----------

test('en la bandeja primero va quien espera (el que más lleva esperando) y luego lo más reciente', function () {
    [$user, $organization] = userWithOrganization();
    $recent = inboxConversation($organization, 'ai', ['last_message_at' => now()]);
    $olderWaiting = inboxConversation($organization, 'waiting', ['handoff_at' => now()->subMinutes(30), 'last_message_at' => now()->subMinutes(5)]);
    $newerWaiting = inboxConversation($organization, 'waiting', ['handoff_at' => now()->subMinutes(2), 'last_message_at' => now()->subMinute()]);
    $old = inboxConversation($organization, 'human', ['last_message_at' => now()->subDay()]);

    $ids = collect($this->actingAs($user)->getJson('/api/conversations')->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$olderWaiting->id, $newerWaiting->id, $recent->id, $old->id]);
});

test('se filtra por estado, y "open" es todo lo no cerrado', function () {
    [$user, $organization] = userWithOrganization();
    foreach (['ai', 'waiting', 'human', 'closed'] as $status) {
        inboxConversation($organization, $status);
    }
    $statuses = fn (string $query) => collect($this->actingAs($user)->getJson("/api/conversations?status={$query}")->json('data'))->pluck('status')->sort()->values()->all();

    expect($statuses('waiting'))->toBe(['waiting'])
        ->and($statuses('closed'))->toBe(['closed'])
        ->and($statuses('open'))->toBe(['ai', 'human', 'waiting']);

    $this->actingAs($user)->getJson('/api/conversations?status=inventado')->assertJsonValidationErrors('status');
});

test('el detalle trae responsable, lead y los mensajes humanos', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'waiting');
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Hola']);
    Lead::create(['contact_id' => $conversation->contact_id, 'conversation_id' => $conversation->id, 'name' => 'Ana', 'phone' => '3001234567']);

    $detail = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}")->assertOk();

    expect($detail->json('assigned_to.id'))->toBe($user->id)
        ->and($detail->json('lead.name'))->toBe('Ana')
        ->and($detail->json('lead.status'))->toBe('new')
        ->and(collect($detail->json('messages'))->pluck('role')->all())->toBe(['user', 'human']);

    $list = $this->actingAs($user)->getJson('/api/conversations')->json('data.0');
    expect($list['lead']['name'])->toBe('Ana');
});

// ---------- seguridad ----------

test('las acciones de la bandeja están abiertas a todo el equipo', function () {
    [, $organization] = userWithOrganization();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    $conversation = inboxConversation($organization, 'waiting');

    $this->actingAs($member)->postJson("/api/conversations/{$conversation->id}/take")->assertOk();
    $this->actingAs($member)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Hola'])->assertCreated();
    $this->actingAs($member)->postJson("/api/conversations/{$conversation->id}/release")->assertOk();
    $this->actingAs($member)->postJson("/api/conversations/{$conversation->id}/close")->assertOk();
});

test('no se puede actuar sobre conversaciones de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    $foreign = inboxConversation($other, 'waiting');

    foreach (['take', 'release', 'close'] as $action) {
        $this->actingAs($user)->postJson("/api/conversations/{$foreign->id}/{$action}")->assertNotFound();
    }
    $this->actingAs($user)->postJson("/api/conversations/{$foreign->id}/messages", ['content' => 'x'])->assertNotFound();

    expect($foreign->fresh()->status->value)->toBe('waiting')->and(Message::withoutGlobalScopes()->where('role', 'human')->count())->toBe(0);
});

test('las acciones exigen sesión y empresa', function () {
    [, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'waiting');

    $this->postJson("/api/conversations/{$conversation->id}/take")->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson("/api/conversations/{$conversation->id}/take")->assertNotFound();
});
