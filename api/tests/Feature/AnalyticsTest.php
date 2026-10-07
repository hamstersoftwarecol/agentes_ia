<?php

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Inbox\ConversationWorkflow;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // «Ahora» fijo: 14 de octubre, 10:00 en Bogotá (UTC-5) = 15:00 UTC.
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00', 'UTC'));
});

/** Empresa con plan y su propietario. */
function analyticsOrg(string $plan = 'business'): array
{
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, $plan);

    return [$owner, $organization];
}

/** Agente con (o sin) base de conocimiento. */
function analyticsAgent(Organization $organization, string $name = 'Sofía', bool $withKnowledge = true): Agent
{
    app(CurrentOrganization::class)->set($organization);

    return Agent::factory()->create(['name' => $name, 'knowledge_base_id' => $withKnowledge ? KnowledgeBase::factory()->create()->id : null]);
}

/**
 * Una conversación con una pregunta del visitante y la respuesta del agente (con o sin fuentes).
 * $at es la hora (UTC) en que empezó.
 */
function talk(Organization $organization, Agent $agent, string $at, string $question = 'Hola', array $sources = [['document_id' => 1]], array $attributes = []): Conversation
{
    app(CurrentOrganization::class)->set($organization);
    $when = Carbon::parse($at, 'UTC');

    $conversation = Conversation::factory()->create($attributes + [
        'agent_id' => $agent->id, 'agent_name' => $agent->name, 'contact_id' => Contact::create(['visitor_id' => (string) Str::uuid()])->id,
        'message_count' => 2, 'created_at' => $when,
    ]);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $question, 'created_at' => $when]);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Respuesta', 'sources' => $sources, 'created_at' => $when->clone()->addSecond()]);

    return $conversation;
}

function leadFor(Conversation $conversation): Lead
{
    app(CurrentOrganization::class)->set($conversation->organization);

    // El lead se captura durante la conversación: misma fecha.
    return Lead::factory()->create(['conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id, 'agent_id' => $conversation->agent_id, 'created_at' => $conversation->created_at]);
}

function analyticsFor(User $user, string $query = ''): TestResponse
{
    resetRequestState();

    return test()->actingAs($user)->getJson('/api/analytics'.$query);
}

test('Starter no tiene analíticas: responde 402 con la función como motivo', function () {
    [$owner] = analyticsOrg('starter');

    analyticsFor($owner)->assertStatus(402)->assertJson(['code' => 'plan_limit', 'reason' => 'analytics']);
});

test('con el plan vencido el motivo es el plan, no la función', function () {
    [$owner, $organization] = analyticsOrg('business');
    setPlan($organization, 'business', ['status' => 'canceled']);

    analyticsFor($owner)->assertStatus(402)->assertJson(['reason' => 'inactive']);
});

test('exige sesión', function () {
    $this->getJson('/api/analytics')->assertUnauthorized();
});

test('Business y Pro las ven', function () {
    [$owner] = analyticsOrg('business');
    [$pro] = analyticsOrg('pro');

    analyticsFor($owner)->assertOk();
    analyticsFor($pro)->assertOk();
});

test('el período solo puede ser 7, 30 o 90 días (30 por defecto)', function () {
    [$owner] = analyticsOrg();

    expect(analyticsFor($owner)->json('days'))->toBe(30)
        ->and(analyticsFor($owner, '?days=7')->json('daily'))->toHaveCount(7)
        ->and(analyticsFor($owner, '?days=90')->json('daily'))->toHaveCount(90);
    analyticsFor($owner, '?days=15')->assertStatus(422)->assertJsonValidationErrors('days');
});

test('una empresa sin actividad responde con ceros y sin porcentajes inventados', function () {
    [$owner] = analyticsOrg();

    $r = analyticsFor($owner)->assertOk()->json();

    expect($r['totals'])->toMatchArray(['conversations' => 0, 'leads' => 0, 'visitor_messages' => 0, 'with_lead_rate' => null, 'handoff_rate' => null, 'ai_only_rate' => null, 'messages_per_conversation' => null, 'first_human_response_minutes' => null])
        ->and($r['by_hour'])->toBe(array_fill(0, 24, 0))
        ->and($r['by_agent'])->toBe([])->and($r['gaps'])->toBe([])
        ->and(array_sum(array_column($r['daily'], 'conversations')))->toBe(0);
});

test('calcula los totales, las tasas y la comparación con el período anterior', function () {
    [$owner, $organization] = analyticsOrg();
    $agent = analyticsAgent($organization);

    $a = talk($organization, $agent, '2026-10-10 14:00:00');
    leadFor($a);
    $b = talk($organization, $agent, '2026-10-11 14:00:00');
    $talk = talk($organization, $agent, '2026-10-12 14:00:00');
    talk($organization, $agent, '2026-10-13 14:00:00');
    // Anterior (30 días antes) y muy anterior (fuera de ambas ventanas).
    talk($organization, $agent, '2026-09-05 14:00:00');
    talk($organization, $agent, '2026-06-01 14:00:00');

    // A pidió una persona y el equipo respondió a los 10 minutos; B también la pidió pero se devolvió a la IA.
    $workflow = app(ConversationWorkflow::class);
    $this->travelTo(Carbon::parse('2026-10-10 14:05:00', 'UTC'));
    $workflow->requestHuman($a, 'visitor');
    $this->travelTo(Carbon::parse('2026-10-10 14:15:00', 'UTC'));
    $workflow->reply($a, $owner, 'Hola, te ayudo');
    $this->travelTo(Carbon::parse('2026-10-11 14:05:00', 'UTC'));
    $workflow->requestHuman($b, 'ai');
    $workflow->release($b);
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00', 'UTC'));

    $r = analyticsFor($owner)->assertOk()->json();

    expect($r['totals'])->toMatchArray([
        'conversations' => 4, 'visitors' => 4, 'visitor_messages' => 4, 'leads' => 1,
        'with_lead_rate' => 25.0, 'handoff_rate' => 50.0, 'ai_only_rate' => 50.0, 'messages_per_conversation' => 2.3, // 2, 2, 2 y 3 (la respuesta humana suma un mensaje)
        'first_human_response_minutes' => 10.0,
    ])->and($r['previous'])->toMatchArray(['conversations' => 1, 'leads' => 0, 'handoff_rate' => 0.0, 'first_human_response_minutes' => null])
        ->and($r['from'])->toBe('2026-09-15')->and($r['to'])->toBe('2026-10-14');
});

test('devolver una conversación a la IA no borra que alguien pidió una persona', function () {
    [, $organization] = analyticsOrg();
    $conversation = talk($organization, analyticsAgent($organization), '2026-10-14 12:00:00');
    $workflow = app(ConversationWorkflow::class);

    $workflow->requestHuman($conversation, 'visitor');
    $first = $conversation->fresh()->handoff_requested_at;
    $workflow->release($conversation->fresh());
    $this->travelTo(now()->addHour());
    $workflow->requestHuman($conversation->fresh(), 'ai');

    $fresh = $conversation->fresh();
    expect($first)->not->toBeNull()->and($fresh->handoff_requested_at->equalTo($first))->toBeTrue() // se guarda la primera vez
        ->and($fresh->handoff_reason)->toBe('ai');
});

test('un mensaje del equipo sin que nadie lo hubiera pedido (intervención) no cuenta como tiempo de respuesta', function () {
    [$owner, $organization] = analyticsOrg();
    $conversation = talk($organization, analyticsAgent($organization), '2026-10-14 12:00:00');
    app(ConversationWorkflow::class)->reply($conversation, $owner, 'Hola, soy una persona');

    $r = analyticsFor($owner)->json('totals');

    expect($r['first_human_response_minutes'])->toBeNull()->and($r['handoff_rate'])->toEqual(0);
});

test('agrupa por día y por hora en la zona horaria de la empresa, no en UTC', function () {
    [$owner, $organization] = analyticsOrg(); // America/Bogota (UTC-5)
    $agent = analyticsAgent($organization);

    // 03:00 UTC del 14 = 22:00 del 13 en Bogotá.
    $late = talk($organization, $agent, '2026-10-14 03:00:00');
    leadFor($late);
    talk($organization, $agent, '2026-10-14 13:00:00');   // 08:00 del 14

    $r = analyticsFor($owner, '?days=7')->json();
    $daily = collect($r['daily'])->keyBy('date');

    expect($daily['2026-10-13'])->toMatchArray(['conversations' => 1, 'leads' => 1])
        ->and($daily['2026-10-14'])->toMatchArray(['conversations' => 1, 'leads' => 0])
        ->and($r['by_hour'][22])->toBe(1)->and($r['by_hour'][8])->toBe(1)->and(array_sum($r['by_hour']))->toBe(2);
});

test('reparte por agente y conserva el nombre de uno que se borró', function () {
    [$owner, $organization] = analyticsOrg();
    $sofia = analyticsAgent($organization, 'Sofía');
    $leo = analyticsAgent($organization, 'Leo');

    $c = talk($organization, $sofia, '2026-10-13 14:00:00');
    leadFor($c);
    app(ConversationWorkflow::class)->requestHuman($c, 'visitor');
    talk($organization, $sofia, '2026-10-13 15:00:00');
    talk($organization, $leo, '2026-10-13 16:00:00');
    $leo->delete();

    $byAgent = collect(analyticsFor($owner)->json('by_agent'))->keyBy('agent');

    expect($byAgent['Sofía'])->toMatchArray(['conversations' => 2, 'leads' => 1, 'handoffs' => 1])
        ->and($byAgent['Leo'])->toMatchArray(['conversations' => 1, 'leads' => 0, 'handoffs' => 0]);
});

test('lista las preguntas que el agente contestó sin encontrar nada en la base de conocimiento', function () {
    [$owner, $organization] = analyticsOrg();
    $agent = analyticsAgent($organization);

    talk($organization, $agent, '2026-10-12 14:00:00', '¿Tienen estacionamiento?', []);
    talk($organization, $agent, '2026-10-13 14:00:00', '  ¿TIENEN estacionamiento?  ', []);          // misma pregunta: se agrupa
    talk($organization, $agent, '2026-10-13 15:00:00', '¿Aceptan tarjeta?', []);
    talk($organization, $agent, '2026-10-13 16:00:00', '¿Cuánto cuesta el corte?', [['document_id' => 1]]); // sí tenía fuentes
    talk($organization, analyticsAgent($organization, 'Sin base', false), '2026-10-13 17:00:00', 'Pregunta a un agente sin base', []);

    $gaps = analyticsFor($owner)->json('gaps');

    expect($gaps)->toHaveCount(2)
        ->and($gaps[0])->toMatchArray(['question' => '¿TIENEN estacionamiento?', 'count' => 2])
        ->and($gaps[1])->toMatchArray(['question' => '¿Aceptan tarjeta?', 'count' => 1]);
});

test('en una conversación larga la pregunta sin respuesta es la que precedió a esa respuesta', function () {
    [$owner, $organization] = analyticsOrg();
    $conversation = talk($organization, analyticsAgent($organization), '2026-10-13 14:00:00', 'Primera pregunta', [['document_id' => 1]]);
    app(CurrentOrganization::class)->set($organization);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Segunda pregunta', 'created_at' => '2026-10-13 14:05:00']);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'No sé', 'sources' => [], 'created_at' => '2026-10-13 14:05:01']);

    expect(array_column(analyticsFor($owner)->json('gaps'), 'question'))->toBe(['Segunda pregunta']);
});

test('una pregunta larga se recorta', function () {
    [$owner, $organization] = analyticsOrg();
    talk($organization, analyticsAgent($organization), '2026-10-13 14:00:00', str_repeat('a', 400), []);

    expect(mb_strlen(analyticsFor($owner)->json('gaps.0.question')))->toBeLessThanOrEqual(160);
});

test('solo mira los datos de la propia empresa', function () {
    [$owner, $organization] = analyticsOrg();
    [, $other] = analyticsOrg();
    talk($organization, analyticsAgent($organization), '2026-10-13 14:00:00', 'Mía', []);
    $theirs = talk($other, analyticsAgent($other, 'Ajena'), '2026-10-13 14:00:00', 'De otra empresa', []);
    leadFor($theirs);
    app(ConversationWorkflow::class)->requestHuman($theirs, 'visitor');

    $r = analyticsFor($owner)->json();

    expect($r['totals'])->toMatchArray(['conversations' => 1, 'leads' => 0, 'handoff_rate' => 0.0])
        ->and(array_column($r['gaps'], 'question'))->toBe(['Mía'])
        ->and(array_column($r['by_agent'], 'agent'))->toBe(['Sofía']);
});
