<?php

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => Carbon::setTestNow('2026-10-15 15:00:00 UTC')); // 10:00 en Bogotá
afterEach(fn () => Carbon::setTestNow());

function conversationAt(Organization $organization, string $utc, int $messages = 2, int $tokens = 0, string $status = 'ai'): Conversation
{
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::first() ?? Agent::factory()->create();
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id, 'agent_name' => $agent->name, 'contact_id' => $contact->id, 'status' => $status,
        'created_at' => $utc, 'last_message_at' => $utc,
    ]);
    foreach (range(1, $messages) as $i) {
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'x', 'created_at' => $utc, 'input_tokens' => $tokens, 'output_tokens' => 0]);
    }

    return $conversation;
}

test('la serie diaria tiene 30 días, termina hoy y se cuenta en la zona horaria de la empresa', function () {
    [$user, $organization] = userWithOrganization();
    $organization->update(['timezone' => 'America/Bogota']);
    conversationAt($organization, '2026-10-15 14:00:00');           // hoy 09:00 Bogotá
    conversationAt($organization, '2026-10-15 03:00:00');           // 14/oct 22:00 Bogotá: cuenta como ayer
    conversationAt($organization, '2026-10-14 20:00:00');           // 14/oct 15:00 Bogotá
    conversationAt($organization, '2026-09-16 06:00:00');           // 16/sep 01:00 Bogotá: primer día de la ventana

    $daily = collect($this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk()->json('daily'));

    expect($daily)->toHaveCount(30)
        ->and($daily->first())->toBe(['date' => '2026-09-16', 'conversations' => 1])
        ->and($daily->last())->toBe(['date' => '2026-10-15', 'conversations' => 1])
        ->and($daily->firstWhere('date', '2026-10-14')['conversations'])->toBe(2)
        ->and($daily->sum('conversations'))->toBe(4)
        ->and($daily->pluck('date')->unique())->toHaveCount(30); // sin días repetidos ni faltantes
});

test('la misma conversación cae en otro día según la zona horaria de la empresa', function () {
    [$user, $organization] = userWithOrganization();
    conversationAt($organization, '2026-10-15 03:00:00');

    $organization->update(['timezone' => 'UTC']);
    $utc = collect($this->actingAs($user)->getJson('/api/dashboard/summary')->json('daily'))->firstWhere('conversations', 1)['date'];
    $organization->update(['timezone' => 'America/Bogota']);
    $user = $user->fresh(); // el modelo en memoria conserva la empresa vieja; en producción cada petición la carga de nuevo
    $bogota = collect($this->actingAs($user)->getJson('/api/dashboard/summary')->json('daily'))->firstWhere('conversations', 1)['date'];

    expect($utc)->toBe('2026-10-15')->and($bogota)->toBe('2026-10-14');
});

test('los totales de 30 días excluyen lo anterior a la ventana', function () {
    [$user, $organization] = userWithOrganization();
    $organization->update(['timezone' => 'America/Bogota']);
    conversationAt($organization, '2026-10-10 12:00:00', messages: 3, tokens: 100);
    conversationAt($organization, '2026-09-16 06:00:00', messages: 2, tokens: 50);  // dentro (día 30)
    conversationAt($organization, '2026-09-16 04:00:00', messages: 5, tokens: 999); // 15/sep 23:00 Bogotá: fuera

    $summary = $this->actingAs($user)->getJson('/api/dashboard/summary')->json();

    expect($summary['conversations_30d'])->toBe(2)
        ->and($summary['messages_30d'])->toBe(5)
        ->and($summary['tokens_30d'])->toBe(3 * 100 + 2 * 50)
        ->and($summary['conversations_total'])->toBe(3);
});

test('cuenta leads, esperas y leads nuevos, y lista los 5 más recientes', function () {
    [$user, $organization] = userWithOrganization();
    conversationAt($organization, '2026-10-15 12:00:00', status: 'waiting');
    conversationAt($organization, '2026-10-15 12:00:00', status: 'waiting');
    conversationAt($organization, '2026-10-15 12:00:00', status: 'human');
    $contact = Contact::first();
    foreach (range(1, 7) as $i) {
        Lead::create([
            'contact_id' => $contact->id, 'name' => "Lead $i", 'phone' => '3001234567',
            'status' => $i <= 2 ? 'contacted' : 'new', 'created_at' => Carbon::now()->subHours(8 - $i),
        ]);
    }
    Lead::create(['contact_id' => $contact->id, 'name' => 'Viejo', 'phone' => '3001234567', 'created_at' => Carbon::now()->subDays(40)]);

    $summary = $this->actingAs($user)->getJson('/api/dashboard/summary')->json();

    expect($summary['waiting_conversations'])->toBe(2)
        ->and($summary['leads_30d'])->toBe(7)
        ->and($summary['new_leads'])->toBe(6) // 5 nuevos de los 7 + el viejo
        ->and(collect($summary['recent_leads'])->pluck('name')->all())->toBe(['Lead 7', 'Lead 6', 'Lead 5', 'Lead 4', 'Lead 3']);
});

test('el resumen solo ve los datos de la empresa actual', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    conversationAt($other, '2026-10-15 12:00:00', messages: 9, tokens: 500, status: 'waiting');
    Lead::create(['contact_id' => Contact::first()->id, 'name' => 'Ajeno', 'phone' => '3001234567']);
    conversationAt($organization, '2026-10-15 12:00:00', messages: 1);

    $summary = $this->actingAs($user)->getJson('/api/dashboard/summary')->json();

    expect($summary['conversations_30d'])->toBe(1)
        ->and($summary['messages_30d'])->toBe(1)
        ->and($summary['tokens_30d'])->toBe(0)
        ->and($summary['leads_30d'])->toBe(0)
        ->and($summary['waiting_conversations'])->toBe(0)
        ->and($summary['recent_leads'])->toBe([]);
});

test('una empresa sin actividad devuelve ceros y la serie completa', function () {
    [$user] = userWithOrganization();

    $summary = $this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk()->json();

    expect($summary['conversations_30d'])->toBe(0)
        ->and($summary['tokens_30d'])->toBe(0)
        ->and($summary['daily'])->toHaveCount(30)
        ->and(collect($summary['daily'])->sum('conversations'))->toBe(0);
});

test('las alertas del menú cuentan lo que pide acción, solo de la empresa actual', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    conversationAt($organization, '2026-10-15 12:00:00', status: 'waiting');
    conversationAt($organization, '2026-10-15 12:00:00', status: 'human');
    conversationAt($other, '2026-10-15 12:00:00', status: 'waiting');
    app(CurrentOrganization::class)->set($organization); // conversationAt dejó fijada la otra empresa
    $contact = Contact::first();
    Lead::create(['contact_id' => $contact->id, 'name' => 'A', 'phone' => '3001234567']);
    Lead::create(['contact_id' => $contact->id, 'name' => 'B', 'phone' => '3001234567', 'status' => 'contacted']);

    $this->actingAs($user)->getJson('/api/dashboard/alerts')->assertOk()->assertExactJson(['waiting_conversations' => 1, 'new_leads' => 1]);
});

test('el resumen y las alertas exigen sesión', function () {
    $this->getJson('/api/dashboard/alerts')->assertUnauthorized();
    $this->getJson('/api/dashboard/summary')->assertUnauthorized();
});
