<?php

use App\Enums\OrganizationRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Notifications\QuotaThresholdReached;
use App\Services\Billing\BillingGate;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Empresa con un plan de cupo chico (1000 en Starter): se llena el consumo previo para acercarse al límite. */
function starterOrganization(int $alreadyUsed = 0): array
{
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual', 'current_period_end' => null]); // cortesía sin vencimiento
    if ($alreadyUsed > 0) {
        usage($organization, $alreadyUsed);
    }

    return [$user, $organization, widgetAgent($organization)];
}

test('cada respuesta de IA a un cliente se registra con sus tokens y su costo', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: ['reply' => 'Hola', 'action' => 'none', 'lead' => null]);

    sendWidget($agent, 'hola')->assertCreated();

    $record = UsageRecord::withoutGlobalScopes()->first();
    expect($record->organization_id)->toBe($organization->id)
        ->and($record->agent_id)->toBe($agent->id)
        ->and($record->conversation_id)->toBe(Conversation::withoutGlobalScopes()->first()->id)
        ->and($record->type)->toBe('chat')->and($record->source)->toBe('widget')->and($record->messages)->toBe(1)
        ->and($record->input_tokens)->toBe(11)->and($record->output_tokens)->toBe(7)
        ->and($record->cost_micros)->toBe(0); // 'fake-1' no tiene precio
});

test('el costo se calcula con el precio del modelo', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(reply: ['reply' => 'Hola', 'action' => 'none', 'lead' => null]);
    config(['ai.pricing.fake-1' => ['input' => 2.0, 'output' => 10.0]]);

    sendWidget($agent, 'hola');

    expect(UsageRecord::withoutGlobalScopes()->first()->cost_micros)->toBe(11 * 2 + 7 * 10);
});

test('al agotarse el cupo el agente deja de contestar, sin llamar al modelo ni guardar nada', function () {
    [, $organization, $agent] = starterOrganization(alreadyUsed: 1000);
    $ai = fakeAI();

    $response = sendWidget($agent, 'hola')->assertForbidden();

    expect($response->json('code'))->toBe('quota_exceeded')
        ->and($response->json('message'))->toContain('contacta directamente')
        ->and($ai->calls)->toBe([])
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(0)
        ->and(UsageRecord::withoutGlobalScopes()->where('source', 'widget')->count())->toBe(1); // solo el de preparación
});

test('con el cupo agotado el widget deja de mostrarse', function () {
    [, , $agent] = starterOrganization(alreadyUsed: 1000);

    test()->getJson("/api/widget/{$agent->public_id}/config")->assertOk()->assertExactJson(['active' => false]);
});

test('el último mensaje disponible se responde y el siguiente ya no', function () {
    [, , $agent] = starterOrganization(alreadyUsed: 999);
    fakeAI();

    $id = sendWidget($agent, 'uno')->assertCreated()->json('conversation_id');
    sendWidget($agent, 'dos', $id)->assertForbidden()->assertJsonPath('code', 'quota_exceeded');
});

test('con el cupo agotado una conversación con una persona sigue funcionando (no gasta IA)', function () {
    [, $organization, $agent] = starterOrganization();
    setPlan($organization, 'business', ['source' => 'manual', 'current_period_end' => null]); // la atención humana es de Business
    fakeAI(reply: ['reply' => 'Te paso con alguien', 'action' => 'request_human', 'lead' => null]);
    $id = sendWidget($agent, 'quiero una persona')->json('conversation_id');
    usage($organization, 5000); // se agota el cupo del plan Business

    sendWidget($agent, 'sigo aquí', $id)->assertCreated()->assertJsonPath('message', null);
});

test('las pruebas del panel y los embeddings se registran pero no cuentan contra el cupo', function () {
    [$user, $organization] = userWithOrganization();
    setPlan($organization, 'starter', ['source' => 'manual']);
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", ['messages' => [['role' => 'user', 'content' => 'precio']]])->assertOk();

    $records = UsageRecord::withoutGlobalScopes()->orderBy('id')->get();
    expect($records->pluck('type')->all())->toBe(['embedding', 'chat'])
        ->and($records->pluck('source')->all())->toBe(['document', 'test'])
        ->and($records->sum('messages'))->toBe(0)
        ->and($records[0]->input_tokens)->toBeGreaterThan(0); // el mock informa tokens
    app()->forgetScopedInstances();
    expect(app(BillingGate::class)->for($organization)->messagesUsed())->toBe(0);
});

test('avisa al propietario al llegar al 80 % y al 100 %, una sola vez por período', function () {
    Notification::fake();
    [$owner, $organization, $agent] = starterOrganization(alreadyUsed: 790);
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    fakeAI();

    // Cada mensaje de un visitante distinto: el límite de 8/min por visitante no es lo que se prueba aquí.
    foreach (range(1, 10) as $i) { // 791 … 800 → cruza el 80 %
        sendWidget($agent, "m$i", visitor: (string) Str::uuid())->assertCreated();
    }
    Notification::assertSentToTimes($owner, QuotaThresholdReached::class, 1);
    Notification::assertSentTo($admin, QuotaThresholdReached::class);
    Notification::assertNotSentTo($member, QuotaThresholdReached::class);

    usage($organization, 190); // 990
    foreach (range(1, 10) as $i) { // 991 … 1000 → 100 %
        sendWidget($agent, "z$i", visitor: (string) Str::uuid())->assertCreated();
    }
    Notification::assertSentToTimes($owner, QuotaThresholdReached::class, 2);

    $mails = collect(Notification::sent($owner, QuotaThresholdReached::class))->pluck('percent')->sort()->values()->all();
    expect($mails)->toBe([80, 100]);
});

test('el aviso se repite en el período siguiente', function () {
    Notification::fake();
    [$owner, $organization, $agent] = starterOrganization(alreadyUsed: 799);
    fakeAI();
    sendWidget($agent, 'uno')->assertCreated(); // 800 → aviso 80 %
    Notification::assertSentToTimes($owner, QuotaThresholdReached::class, 1);

    Carbon::setTestNow(now()->addMonth()->startOfMonth()->addDay()); // mes nuevo: consumo en cero
    app()->forgetScopedInstances();
    usage($organization, 799);
    sendWidget($agent, 'dos')->assertCreated();

    Notification::assertSentToTimes($owner, QuotaThresholdReached::class, 2);
    Carbon::setTestNow();
});

test('el correo de cupo resume el consumo y enlaza a facturación', function () {
    [$owner] = userWithOrganization();

    $mail = (new QuotaThresholdReached(100, 'Starter', 1000, 1000, '1 de noviembre'))->toMail($owner);

    expect($mail->subject)->toContain('Se agotaron')
        ->and(implode(' ', $mail->introLines))->toContain('1000 de 1000')->toContain('1 de noviembre')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url').'/billing');
});

test('sin plan vigente el widget no se muestra ni responde', function (string $status, array $attributes) {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    setPlan($organization, 'pro', ['status' => $status] + $attributes);
    $ai = fakeAI();

    test()->getJson("/api/widget/{$agent->public_id}/config")->assertExactJson(['active' => false]);
    sendWidget($agent, 'hola')->assertForbidden()->assertJsonPath('code', 'unavailable');

    expect($ai->calls)->toBe([])->and(Message::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'prueba vencida' => ['trialing', ['source' => 'trial', 'trial_ends_at' => now()->subDay()]],
    'cancelada' => ['canceled', ['source' => 'stripe']],
    'impaga' => ['unpaid', ['source' => 'stripe']],
]);

test('una empresa suspendida no atiende', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $organization->forceFill(['suspended_at' => now()])->save();

    sendWidget($agent, 'hola')->assertForbidden()->assertJsonPath('code', 'unavailable');
});

test('bajar de plan no deja agentes de más atendiendo', function () {
    [, $organization] = userWithOrganization();
    setPlan($organization, 'business', ['source' => 'manual']); // 3 agentes
    app(CurrentOrganization::class)->set($organization);
    $agents = collect(range(1, 4))->map(fn ($i) => Agent::factory()->create(['name' => "A$i"]));
    setPlan($organization, 'starter', ['source' => 'manual']); // baja a 1 agente
    fakeAI();

    sendWidget($agents[0], 'hola')->assertCreated();
    sendWidget($agents[1], 'hola')->assertForbidden()->assertJsonPath('code', 'unavailable');
    test()->getJson("/api/widget/{$agents[3]->public_id}/config")->assertExactJson(['active' => false]);
});
