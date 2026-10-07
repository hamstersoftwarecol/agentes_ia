<?php

use App\Contracts\AIProvider;
use App\Enums\OrganizationRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ConversationNeedsHuman;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Notification;

function requestHumanTurn(string $reply = 'Claro, te transfiero con una persona.'): array
{
    return ['reply' => $reply, 'action' => 'request_human', 'lead' => null];
}

/** Abre una conversación en la que el agente ya pasó al cliente a una persona. */
function waitingConversation(Organization $organization): array
{
    $agent = widgetAgent($organization);
    $ai = fakeAI(reply: requestHumanTurn());
    $id = sendWidget($agent, 'Quiero hablar con una persona')->json('conversation_id');

    return [$agent, $id, $ai, Conversation::withoutGlobalScopes()->where('public_id', $id)->first()];
}

function pollWidget(string $agentPublicId, string $conversationId, int $after = 0, string $visitor = VISITOR)
{
    return test()->getJson("/api/widget/{$agentPublicId}/conversations/{$conversationId}/messages?after={$after}", ['X-Visitor-Id' => $visitor]);
}

test('cuando el agente pide una persona la conversación pasa a "en espera" y la IA responde el aviso', function () {
    Notification::fake();
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: requestHumanTurn('Claro, te paso con una persona.'));

    $response = sendWidget($agent, 'Quiero hablar con alguien')->assertCreated();

    expect($response->json('status'))->toBe('waiting')
        ->and($response->json('message.content'))->toBe('Claro, te paso con una persona.');

    $conversation = Conversation::withoutGlobalScopes()->first();
    expect($conversation->status->value)->toBe('waiting')
        ->and($conversation->handoff_reason)->toBe('ai')
        ->and($conversation->handoff_at)->not->toBeNull()
        ->and($conversation->assigned_user_id)->toBeNull();
});

test('una acción "none" no cambia el estado', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: ['reply' => 'Claro', 'action' => 'none', 'lead' => null]);

    $response = sendWidget($agent, 'hola')->assertCreated();

    expect($response->json('status'))->toBe('ai')->and(Conversation::withoutGlobalScopes()->first()->handoff_at)->toBeNull();
});

test('se avisa por correo a todo el equipo de esa empresa, y solo a ella', function () {
    Notification::fake();
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    [$outsider] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: requestHumanTurn());

    sendWidget($agent, 'persona por favor');

    Notification::assertSentTo([$owner, $admin, $member], ConversationNeedsHuman::class);
    Notification::assertNotSentTo($outsider, ConversationNeedsHuman::class);
    Notification::assertSentTimes(ConversationNeedsHuman::class, 3);
});

test('el correo de aviso se puede desactivar', function () {
    Notification::fake();
    config(['inbox.notify_by_email' => false]);
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: requestHumanTurn());

    sendWidget($agent, 'persona');

    Notification::assertNothingSent();
    expect(Conversation::withoutGlobalScopes()->first()->status->value)->toBe('waiting');
});

test('el correo trae un enlace a la conversación y el nombre del cliente', function () {
    [, $organization] = userWithOrganization();
    [$owner] = [User::factory()->create(['name' => 'Laura Gómez'])];
    $organization->users()->attach($owner, ['role' => 'owner']);
    [$agent, , , $conversation] = waitingConversation($organization);

    $mail = (new ConversationNeedsHuman($conversation))->toMail($owner);

    expect($mail->subject)->toContain('persona')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url')."/conversations?c={$conversation->id}")
        ->and(implode(' ', $mail->introLines))->toContain('Visitante #')->toContain($conversation->agent_name);
});

test('mientras espera, el mensaje del cliente se guarda pero la IA no responde', function () {
    [, $organization] = userWithOrganization();
    [$agent, $id, $ai] = waitingConversation($organization);
    $callsBefore = count($ai->calls);

    $response = sendWidget($agent, '¿Siguen ahí?', $id)->assertCreated();

    expect($response->json('message'))->toBeNull()
        ->and($response->json('status'))->toBe('waiting')
        ->and($response->json('conversation_id'))->toBe($id)
        ->and(count($ai->calls))->toBe($callsBefore);

    $conversation = Conversation::withoutGlobalScopes()->where('public_id', $id)->first();
    expect($conversation->message_count)->toBe(3)
        ->and(Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest('id')->first()->content)->toBe('¿Siguen ahí?');
});

test('la respuesta de una persona le llega al visitante por el sondeo, con su nombre de pila', function () {
    [$user, $organization] = userWithOrganization();
    $user->forceFill(['name' => 'Carlos Andrés Ruiz'])->save();
    [$agent, $id, , $conversation] = waitingConversation($organization);
    $lastSeen = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->max('id');

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Hola, soy Carlos. ¿En qué te ayudo?'])->assertCreated();

    $poll = pollWidget($agent->public_id, $id, $lastSeen)->assertOk();
    expect($poll->json('status'))->toBe('human')
        ->and($poll->json('agent_name'))->toBe('Carlos')
        ->and($poll->json('messages'))->toHaveCount(1)
        ->and($poll->json('messages.0.role'))->toBe('human')
        ->and($poll->json('messages.0.sender_name'))->toBe('Carlos')
        ->and($poll->json('messages.0.content'))->toBe('Hola, soy Carlos. ¿En qué te ayudo?')
        // No se filtra nada interno del equipo.
        ->and(array_keys($poll->json('messages.0')))->toBe(['id', 'role', 'content', 'sender_name', 'created_at']);
});

test('el sondeo solo devuelve los mensajes posteriores a "after"', function () {
    [, $organization] = userWithOrganization();
    [$agent, $id, , $conversation] = waitingConversation($organization);
    $ids = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('id')->all();

    expect(pollWidget($agent->public_id, $id, 0)->json('messages'))->toHaveCount(2)
        ->and(pollWidget($agent->public_id, $id, $ids[0])->json('messages'))->toHaveCount(1)
        ->and(pollWidget($agent->public_id, $id, $ids[1])->json('messages'))->toBe([]);
});

test('el sondeo exige ser el dueño de la conversación', function () {
    [, $organization] = userWithOrganization();
    [$agent, $id] = waitingConversation($organization);

    pollWidget($agent->public_id, $id, 0, OTHER_VISITOR)->assertNotFound();
    test()->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages?after=0")->assertUnprocessable();
});

test('el sondeo tiene su propio límite, más amplio que el de los mensajes', function () {
    [, $organization] = userWithOrganization();
    [$agent, $id] = waitingConversation($organization);

    foreach (range(1, 40) as $i) {
        pollWidget($agent->public_id, $id)->assertOk();
    }
    pollWidget($agent->public_id, $id)->assertStatus(429);
});

test('con una persona atendiendo la IA sigue callada', function () {
    [$user, $organization] = userWithOrganization();
    [$agent, $id, $ai, $conversation] = waitingConversation($organization);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/take")->assertOk();
    $calls = count($ai->calls);

    sendWidget($agent, 'gracias, espero', $id)->assertCreated()->assertJsonPath('message', null)->assertJsonPath('status', 'human');

    expect(count($ai->calls))->toBe($calls);
});

test('al devolverla a la IA vuelve a responder, con las respuestas humanas como contexto', function () {
    [$user, $organization] = userWithOrganization();
    [$agent, $id, $ai, $conversation] = waitingConversation($organization);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Te lo resuelvo yo']);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/release")->assertOk()->assertJsonPath('status', 'ai');
    fakeAI(reply: ['reply' => 'Seguimos con la IA', 'action' => 'none', 'lead' => null]);
    $ai = app(AIProvider::class);

    $response = sendWidget($agent, '¿y el precio?', $id)->assertCreated();

    expect($response->json('message.content'))->toBe('Seguimos con la IA')
        ->and($response->json('status'))->toBe('ai');
    $sent = collect(end($ai->calls)['messages'])->map(fn ($m) => [$m->role, $m->content])->all();
    expect($sent)->toContain(['assistant', 'Te lo resuelvo yo'])->and(end($sent))->toBe(['user', '¿y el precio?']);
});

test('una conversación cerrada no se reabre: el siguiente mensaje inicia una nueva con contexto limpio', function () {
    [$user, $organization] = userWithOrganization();
    [$agent, $id, , $conversation] = waitingConversation($organization);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/close")->assertOk();
    $ai = fakeAI(reply: ['reply' => 'Nueva conversación', 'action' => 'none', 'lead' => null]);

    $response = sendWidget($agent, 'hola otra vez', $id)->assertCreated();

    expect($response->json('conversation_id'))->not->toBe($id)
        ->and($response->json('status'))->toBe('ai')
        ->and(count($ai->calls[0]['messages']))->toBe(1) // solo el mensaje nuevo
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(2)
        ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->status->value)->toBe('closed');
});

test('el visitante ve el cierre en el sondeo', function () {
    [$user, $organization] = userWithOrganization();
    [$agent, $id, , $conversation] = waitingConversation($organization);
    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/close");

    expect(pollWidget($agent->public_id, $id)->json('status'))->toBe('closed');
});

test('el correo de aviso se arma bien en un worker: sin empresa fijada y tras serializar la notificación', function () {
    [$owner, $organization] = userWithOrganization();
    [, , , $conversation] = waitingConversation($organization);

    // Lo que hace la cola: serializa la notificación, y el worker la restaura sin usuario ni empresa actual.
    $restored = unserialize(serialize(new ConversationNeedsHuman($conversation)));
    app(CurrentOrganization::class)->set(null);

    $mail = $restored->toMail($owner);

    expect(implode(' ', $mail->introLines))->toContain('Visitante #')->not->toContain('Visitante #0');
    // El contexto queda restaurado para el resto del trabajo del job.
    expect(app(CurrentOrganization::class)->id())->toBe($organization->id);
});
