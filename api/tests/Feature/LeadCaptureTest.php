<?php

use App\Contracts\AIProvider;
use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Notifications\ConversationNeedsHuman;
use App\Notifications\NewLeadCaptured;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function leadTurn(array $lead, string $reply = '¡Gracias! Un asesor te contactará.'): array
{
    return ['reply' => $reply, 'action' => 'none', 'lead' => $lead + ['name' => null, 'phone' => null, 'email' => null, 'interest' => null]];
}

test('cuando el agente reúne los datos se crea el lead y el contacto deja de ser anónimo', function () {
    Notification::fake();
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: leadTurn(['name' => 'Alejandro', 'phone' => '300 123 4567', 'interest' => 'Plan empresarial']));

    $response = sendWidget($agent, 'Soy Alejandro, mi WhatsApp es 300 123 4567')->assertCreated();

    expect($response->json('message.content'))->toContain('Gracias');

    $lead = Lead::withoutGlobalScopes()->first();
    $conversation = Conversation::withoutGlobalScopes()->first();
    $contact = Contact::withoutGlobalScopes()->first();

    expect($lead->status->value)->toBe('new')
        ->and($lead->name)->toBe('Alejandro')
        ->and($lead->phone)->toBe('3001234567')
        ->and($lead->interest)->toBe('Plan empresarial')
        ->and($lead->organization_id)->toBe($organization->id)
        ->and($lead->conversation_id)->toBe($conversation->id)
        ->and($lead->agent_id)->toBe($agent->id)
        ->and($lead->contact_id)->toBe($contact->id)
        ->and($contact->name)->toBe('Alejandro')
        ->and($contact->phone)->toBe('3001234567')
        ->and($contact->displayName())->toBe('Alejandro');
});

test('sin teléfono ni correo no se crea ningún lead', function () {
    Notification::fake();
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: leadTurn(['name' => 'Alejandro', 'interest' => 'Plan empresarial']));

    sendWidget($agent, 'Soy Alejandro y me interesa el plan')->assertCreated();

    expect(Lead::withoutGlobalScopes()->count())->toBe(0)->and(Contact::withoutGlobalScopes()->first()->name)->toBeNull();
    Notification::assertNothingSent();
});

test('un dato inválido se descarta pero se conserva el válido', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: leadTurn(['name' => 'Ana', 'phone' => '12345', 'email' => 'ANA@Example.com']));

    sendWidget($agent, 'mis datos')->assertCreated();

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->phone)->toBeNull()->and($lead->email)->toBe('ana@example.com');
});

test('los datos nuevos se fusionan en el mismo lead sin duplicarlo ni repetir el aviso', function () {
    Notification::fake();
    [$owner, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $turns = [
        leadTurn(['name' => 'Alejandro', 'phone' => '3001234567']),
        leadTurn(['name' => 'Alejandro', 'phone' => '3001234567', 'email' => 'ale@example.com', 'interest' => 'Plan Pro']),
        leadTurn(['phone' => '3109998877']),                       // el cliente corrige su teléfono
        ['reply' => 'De nada', 'action' => 'none', 'lead' => null], // sin datos nuevos: no borra nada
    ];
    fakeAI(reply: fn (int $call) => $turns[$call - 1]);

    $id = sendWidget($agent, 'uno')->json('conversation_id');
    $leadId = Lead::withoutGlobalScopes()->first()->id;
    Lead::withoutGlobalScopes()->find($leadId)->update(['status' => 'contacted', 'notes' => 'Llamar mañana']);
    foreach (['dos', 'tres', 'cuatro'] as $text) {
        sendWidget($agent, $text, $id)->assertCreated();
    }

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->id)->toBe($leadId)
        ->and($lead->phone)->toBe('3109998877')           // corregido
        ->and($lead->email)->toBe('ale@example.com')       // agregado después
        ->and($lead->name)->toBe('Alejandro')              // un null nuevo no lo borra
        ->and($lead->interest)->toBe('Plan Pro')
        ->and($lead->status->value)->toBe('contacted')     // el trabajo del equipo no se pisa
        ->and($lead->notes)->toBe('Llamar mañana');

    Notification::assertSentToTimes($owner, NewLeadCaptured::class, 1); // solo al crearse
});

test('otra conversación del mismo visitante genera otro lead', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: leadTurn(['name' => 'Ana', 'phone' => '3001234567']));

    sendWidget($agent, 'primera');
    sendWidget($agent, 'segunda'); // sin conversation_id: conversación nueva

    expect(Lead::withoutGlobalScopes()->count())->toBe(2)->and(Contact::withoutGlobalScopes()->count())->toBe(1);
});

test('el aviso de lead va solo a propietario y administradores', function () {
    Notification::fake();
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    [$outsider] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: leadTurn(['name' => 'Ana', 'phone' => '3001234567', 'interest' => 'Reserva']));

    sendWidget($agent, 'quiero reservar');

    Notification::assertSentTo([$owner, $admin], NewLeadCaptured::class);
    Notification::assertNotSentTo([$member, $outsider], NewLeadCaptured::class);
});

test('el correo del lead resume los datos y enlaza al panel', function () {
    [$owner, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);
    $lead = Lead::create(['contact_id' => $contact->id, 'name' => 'Ana Pérez', 'phone' => '3001234567', 'email' => null, 'interest' => 'Reserva para el sábado']);

    $mail = (new NewLeadCaptured($lead))->toMail($owner);

    expect($mail->subject)->toBe('Nuevo lead: Ana Pérez')
        ->and(implode(' ', $mail->introLines))->toContain('Ana Pérez')->toContain('3001234567')->toContain('Reserva para el sábado')->not->toContain('Correo')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url').'/leads');
});

test('el lead siempre queda en la empresa del agente aunque hubiera otra fijada de antes', function () {
    [, $orgA] = userWithOrganization();
    [, $orgB] = userWithOrganization();
    $agentA = widgetAgent($orgA);
    fakeAI(reply: leadTurn(['name' => 'Ana', 'phone' => '3001234567']));
    app(CurrentOrganization::class)->set($orgB);

    sendWidget($agentA, 'hola')->assertCreated();

    expect(Lead::withoutGlobalScopes()->first()->organization_id)->toBe($orgA->id);
});

test('recorrido completo con el proveedor simulado real: intención → datos → lead → pide una persona', function () {
    Notification::fake();
    [$owner, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    app()->forgetInstance(AIProvider::class); // usa el MockAIProvider de verdad

    $first = sendWidget($agent, 'Quiero contratar el plan empresarial')->assertCreated();
    expect($first->json('message.content'))->toContain('cómo te llamas')->and(Lead::withoutGlobalScopes()->count())->toBe(0);

    $id = $first->json('conversation_id');
    $second = sendWidget($agent, 'Me llamo Alejandro Pérez, mi WhatsApp es 300 123 4567', $id)->assertCreated();
    expect($second->json('message.content'))->toContain('Gracias Alejandro Pérez');

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->name)->toBe('Alejandro Pérez')->and($lead->phone)->toBe('3001234567')
        ->and($lead->interest)->toBe('Quiero contratar el plan empresarial');

    $third = sendWidget($agent, 'Prefiero hablar con una persona', $id)->assertCreated();
    expect($third->json('status'))->toBe('waiting');
    Notification::assertSentTo($owner, NewLeadCaptured::class);
    Notification::assertSentTo($owner, ConversationNeedsHuman::class);
});

test('el correo de lead también se arma bien en un worker sin empresa fijada', function () {
    [$owner, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);
    $lead = Lead::create(['contact_id' => $contact->id, 'name' => 'Ana Pérez', 'phone' => '3001234567']);

    $restored = unserialize(serialize(new NewLeadCaptured($lead)));
    app(CurrentOrganization::class)->set(null);

    expect($restored->toMail($owner)->subject)->toBe('Nuevo lead: Ana Pérez')
        ->and(app(CurrentOrganization::class)->id())->toBe($organization->id);
});
