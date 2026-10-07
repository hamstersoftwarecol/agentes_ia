<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers compartidos
|--------------------------------------------------------------------------
*/

use App\Contracts\AIProvider;
use App\Enums\OrganizationRole;
use App\Jobs\ProcessDocument;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Services\AI\ChatResponse;
use App\Support\CurrentOrganization;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;

/** Usuario verificado con empresa propia (por defecto, como propietario). */
function userWithOrganization(OrganizationRole $role = OrganizationRole::Owner, ?Organization $organization = null): array
{
    $user = User::factory()->create();
    $organization ??= Organization::factory()->create();
    $organization->users()->attach($user, ['role' => $role->value]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/** PDF mínimo válido con una línea de texto (ASCII). Con $text vacío no tiene texto, como un escaneo. */
function minimalPdf(string $text): string
{
    $stream = $text === '' ? 'BT ET' : "BT /F1 12 Tf 72 720 Td ($text) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n$object\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

/**
 * Crea una base de conocimiento con documentos ya procesados (cola síncrona en tests).
 *
 * @param  array<string, string>  $docs  título => contenido
 */
function seedKnowledge(Organization $organization, array $docs, string $name = 'Base'): KnowledgeBase
{
    app(CurrentOrganization::class)->set($organization);
    $kb = KnowledgeBase::factory()->create(['name' => $name]);

    foreach ($docs as $title => $content) {
        $document = Document::factory()->create(['knowledge_base_id' => $kb->id, 'title' => $title, 'content' => $content]);
        ProcessDocument::dispatchSync($document->id);
    }

    return $kb;
}

/** Agente de la empresa con una base de conocimiento ya cargada. */
function agentWithKnowledge(Organization $organization, array $docs, array $agent = []): Agent
{
    $kb = seedKnowledge($organization, $docs);

    return Agent::factory()->create($agent + ['name' => 'Sofía', 'knowledge_base_id' => $kb->id]);
}

/* ---------- widget / chat ---------- */

const VISITOR = '6f1c9a52-3b0e-4c5d-9a2f-0d1e2f3a4b5c';
const OTHER_VISITOR = 'b8e4d7c1-2a3f-4e5b-8c9d-1a2b3c4d5e6f';

function widgetAgent(Organization $organization, array $attributes = []): Agent
{
    app(CurrentOrganization::class)->set($organization);

    return Agent::factory()->create($attributes + ['name' => 'Sofía']);
}

/**
 * Proveedor de IA falso que registra lo que recibe.
 *
 * @param  string|array|Closure|null  $reply  Texto plano, un turno estructurado (array → se codifica a JSON) o una función que decide por llamada.
 */
function fakeAI(?Throwable $failWith = null, string|array|Closure|null $reply = null): object
{
    $fake = new class($failWith, $reply) implements AIProvider
    {
        public array $calls = [];

        public function __construct(private readonly ?Throwable $failWith, private readonly string|array|Closure|null $reply) {}

        public function chat(array $messages, ?string $system = null, ?array $jsonSchema = null): ChatResponse
        {
            $this->calls[] = ['messages' => $messages, 'system' => $system, 'schema' => $jsonSchema];
            if ($this->failWith) {
                throw $this->failWith;
            }

            $reply = $this->reply instanceof Closure ? ($this->reply)(count($this->calls), $messages) : $this->reply;
            $content = is_array($reply) ? json_encode($reply, JSON_UNESCAPED_UNICODE) : ($reply ?? 'Respuesta simulada');

            return new ChatResponse($content, 'fake-1', 11, 7);
        }
    };
    app()->instance(AIProvider::class, $fake);

    return $fake;
}

function sendWidget(Agent $agent, string $text, ?string $conversationId = null, string $visitor = VISITOR, array $extra = [])
{
    return test()->postJson(
        "/api/widget/{$agent->public_id}/messages",
        array_filter(['message' => $text, 'conversation_id' => $conversationId] + $extra),
        ['X-Visitor-Id' => $visitor],
    );
}

/* ---------- bandeja ---------- */

/** Conversación con un mensaje del visitante, en el estado indicado. */
function inboxConversation(Organization $organization, string $status = 'ai', array $attributes = []): Conversation
{
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::where('name', 'Sofía')->first() ?? Agent::factory()->create(['name' => 'Sofía']);
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);

    $conversation = Conversation::factory()->create($attributes + [
        'agent_id' => $agent->id, 'agent_name' => 'Sofía', 'contact_id' => $contact->id, 'status' => $status,
        'handoff_at' => $status === 'waiting' ? now() : null,
    ]);
    Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Hola']);
    $conversation->forceFill(['message_count' => 1])->save();

    return $conversation;
}

/* ---------- facturación ---------- */

/** Reemplaza la suscripción de cortesía que trae la fábrica por la que necesite el test. */
function setPlan(Organization $organization, string $plan, array $attributes = []): Subscription
{
    Subscription::where('organization_id', $organization->id)->delete();
    $subscription = Subscription::factory()->plan($plan)->create(['organization_id' => $organization->id] + $attributes);

    resetRequestState(); // el BillingGate guarda en memoria lo ya calculado en esta petición

    return $subscription;
}

/** Un mensaje de IA ya registrado en el consumo de la empresa. */
function usage(Organization $organization, int $messages = 1, string $source = 'widget', $at = null, array $extra = []): void
{
    UsageRecord::withoutGlobalScopes()->create([
        'organization_id' => $organization->id,
        'type' => $extra['type'] ?? 'chat',
        'source' => $source,
        'messages' => $messages,
        'created_at' => $at ?? now(),
    ] + $extra);

    resetRequestState();
}

/**
 * Lo que en producción se reinicia solo entre peticiones: servicios "scoped" y controladores que el router
 * conserva (con sus dependencias inyectadas) mientras dura el test.
 */
function resetRequestState(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

function createAgentIn(Organization $organization, array $attributes = []): Agent
{
    app(CurrentOrganization::class)->set($organization);

    return Agent::factory()->create($attributes);
}

/* ---------- panel de superadmin ---------- */

function superadmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['is_superadmin' => true])->save();

    return $user;
}

/**
 * Enlaces de invitación enviados por correo (con Notification::fake()), del más antiguo al más reciente.
 * La API nunca los devuelve: el token solo existe en el correo.
 *
 * @return list<string>
 */
function invitationUrls(): array
{
    return Notification::sent(new AnonymousNotifiable, TeamInvitation::class)
        ->pluck('url')->values()->all();
}

function lastInvitationUrl(): string
{
    return Arr::last(invitationUrls());
}
