<?php

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIProviderException;
use App\Services\AI\ChatMessage;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ---------- configuración pública ----------

test('la configuración pública expone solo lo necesario para pintar el widget', function () {
    [, $organization] = userWithOrganization();
    $organization->update(['name' => 'Barbería XYZ']);
    $agent = widgetAgent($organization, ['welcome_message' => '¡Hola!', 'widget_position' => 'left', 'widget_color' => '#112233']);

    $response = $this->getJson("/api/widget/{$agent->public_id}/config")->assertOk();

    expect($response->json())->toBe([
        'active' => true,
        'name' => 'Sofía',
        'company' => 'Barbería XYZ',
        'logo_url' => null,
        'welcome_message' => '¡Hola!',
        'position' => 'left',
        'color' => '#112233',
    ]);
});

test('un agente desactivado no revela nada y marca active=false', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization, ['is_active' => false]);

    $this->getJson("/api/widget/{$agent->public_id}/config")->assertOk()->assertExactJson(['active' => false]);
});

test('un agente inexistente o con identificador mal formado responde 404', function () {
    $this->getJson('/api/widget/ag_NoExisteXX/config')->assertNotFound();
    $this->getJson('/api/widget/1/config')->assertNotFound();
    $this->getJson('/api/widget/ag_corto/config')->assertNotFound();
});

// ---------- enviar mensajes ----------

test('el primer mensaje crea contacto, conversación y los dos mensajes', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    $response = sendWidget($agent, 'Hola, ¿tienen cita hoy?', extra: ['page_url' => 'https://tienda.com/servicios?utm=x&email=a@b.c#seccion'])->assertCreated();

    expect(Str::isUuid($response->json('conversation_id')))->toBeTrue()
        ->and($response->json('message.role'))->toBe('assistant')
        ->and($response->json('message.content'))->toContain('Hola, ¿tienen cita hoy?');

    $conversation = Conversation::withoutGlobalScopes()->first();
    expect($conversation->organization_id)->toBe($organization->id)
        ->and($conversation->agent_id)->toBe($agent->id)
        ->and($conversation->agent_name)->toBe('Sofía')
        ->and($conversation->message_count)->toBe(2)
        ->and($conversation->status->value)->toBe('ai')
        ->and($conversation->page_url)->toBe('https://tienda.com/servicios') // sin query ni fragmento
        ->and($conversation->last_message_at)->not->toBeNull();

    $roles = Message::withoutGlobalScopes()->orderBy('id')->pluck('role')->map->value->all();
    expect($roles)->toBe(['user', 'assistant'])
        ->and(Contact::withoutGlobalScopes()->first()->visitor_id)->toBe(VISITOR)
        ->and(Message::withoutGlobalScopes()->where('role', 'assistant')->first()->organization_id)->toBe($organization->id);
});

test('las fechas se guardan en UTC aunque el servidor de BD use otra zona horaria', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    DB::statement("SET TIME ZONE 'America/Bogota'"); // simula un Postgres en hora local

    sendWidget($agent, 'hola')->assertCreated();

    $message = Message::withoutGlobalScopes()->first();
    $contact = Contact::withoutGlobalScopes()->first();
    expect($message->created_at->diffInSeconds(now(), absolute: true))->toBeLessThan(5)
        ->and($contact->first_seen_at->diffInSeconds(now(), absolute: true))->toBeLessThan(5)
        ->and($contact->last_seen_at->diffInSeconds(now(), absolute: true))->toBeLessThan(5);
});

test('al visitante solo se le devuelve el contenido, sin modelo, tokens ni fuentes', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI();

    $message = sendWidget($agent, 'hola')->json('message');

    expect(array_keys($message))->toBe(['id', 'role', 'content', 'created_at']);
});

test('la respuesta usa el conocimiento del agente y registra tokens y fuentes', function () {
    [, $organization] = userWithOrganization();
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte de cabello cuesta 25.000 pesos.']);

    $response = sendWidget($agent, '¿Cuánto cuesta el corte de cabello?')->assertCreated();

    expect($response->json('message.content'))->toContain('25.000');

    $assistant = Message::withoutGlobalScopes()->where('role', 'assistant')->first();
    expect($assistant->sources[0]['title'])->toBe('Precios')
        ->and($assistant->model)->toBe('mock-1')
        ->and($assistant->input_tokens)->toBeGreaterThan(0);
});

test('los mensajes siguientes continúan la misma conversación y el modelo recibe el historial real', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $ai = fakeAI();

    $conversationId = sendWidget($agent, 'primero')->json('conversation_id');
    sendWidget($agent, 'segundo', $conversationId)->assertCreated()->assertJsonPath('conversation_id', $conversationId);

    expect(Conversation::withoutGlobalScopes()->count())->toBe(1)
        ->and(Conversation::withoutGlobalScopes()->first()->message_count)->toBe(4)
        ->and(Contact::withoutGlobalScopes()->count())->toBe(1);

    $sent = collect($ai->calls[1]['messages'])->map(fn (ChatMessage $m) => [$m->role, $m->content])->all();
    expect($sent)->toBe([['user', 'primero'], ['assistant', 'Respuesta simulada'], ['user', 'segundo']]);
});

test('al modelo solo se le envían los últimos mensajes del historial', function () {
    [, $organization] = userWithOrganization();
    config(['widget.history_messages' => 4, 'widget.max_messages_per_conversation' => 100]);
    $agent = widgetAgent($organization);
    $ai = fakeAI();

    $conversationId = sendWidget($agent, 'm1')->json('conversation_id');
    foreach (['m2', 'm3', 'm4'] as $text) {
        sendWidget($agent, $text, $conversationId);
    }
    sendWidget($agent, 'm5', $conversationId);

    $last = collect(end($ai->calls)['messages'])->map(fn (ChatMessage $m) => $m->content)->all();
    expect($last)->toHaveCount(5) // 4 de historial + el nuevo
        ->and(last($last))->toBe('m5')
        ->and($last)->not->toContain('m1');
});

test('el mismo visitante reutiliza su contacto entre conversaciones; otro visitante es otro contacto', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    sendWidget($agent, 'uno');
    sendWidget($agent, 'dos'); // sin conversation_id: abre otra
    sendWidget($agent, 'tres', visitor: OTHER_VISITOR);

    expect(Conversation::withoutGlobalScopes()->count())->toBe(3)
        ->and(Contact::withoutGlobalScopes()->count())->toBe(2);
});

test('valida visitante, mensaje y formato de los identificadores', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $url = "/api/widget/{$agent->public_id}/messages";

    $this->postJson($url, ['message' => 'hola'])->assertUnprocessable()->assertJsonValidationErrors('visitor_id');
    $this->postJson($url, ['message' => 'hola'], ['X-Visitor-Id' => 'no-es-uuid'])->assertJsonValidationErrors('visitor_id');
    $this->postJson($url, ['message' => ''], ['X-Visitor-Id' => VISITOR])->assertJsonValidationErrors('message');
    $this->postJson($url, ['message' => '   '], ['X-Visitor-Id' => VISITOR])->assertJsonValidationErrors('message');
    $this->postJson($url, ['message' => str_repeat('a', 1001)], ['X-Visitor-Id' => VISITOR])->assertJsonValidationErrors('message');
    $this->postJson($url, ['message' => 'hola', 'conversation_id' => '123'], ['X-Visitor-Id' => VISITOR])->assertJsonValidationErrors('conversation_id');
    $this->postJson($url, ['message' => ['x']], ['X-Visitor-Id' => VISITOR])->assertJsonValidationErrors('message');

    expect(Conversation::withoutGlobalScopes()->count())->toBe(0);
});

test('un agente desactivado no responde ni guarda nada', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization, ['is_active' => false]);
    $ai = fakeAI();

    sendWidget($agent, 'hola')->assertForbidden();

    expect(Conversation::withoutGlobalScopes()->count())->toBe(0)->and($ai->calls)->toBe([]);
});

test('si el proveedor de IA falla se responde 503 amable y no se guarda nada para poder reintentar', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    fakeAI(new AIProviderException('API caída: clave sk-secreta inválida'));

    $response = sendWidget($agent, 'hola')->assertStatus(503);

    expect($response->json('message'))->toContain('no está disponible')
        ->and($response->getContent())->not->toContain('sk-secreta') // el detalle técnico jamás llega al visitante
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(0)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

test('una conversación tiene un tope de mensajes', function () {
    [, $organization] = userWithOrganization();
    config(['widget.max_messages_per_conversation' => 4]);
    $agent = widgetAgent($organization);

    $id = sendWidget($agent, 'a')->json('conversation_id');
    sendWidget($agent, 'b', $id)->assertCreated();   // llega a 4
    sendWidget($agent, 'c', $id)->assertStatus(429)->assertJsonPath('code', 'conversation_limit');
});

// ---------- aislamiento ----------

test('nadie puede usar ni leer la conversación de otro visitante', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $id = sendWidget($agent, 'secreto del visitante A')->json('conversation_id');

    sendWidget($agent, 'intento', $id, OTHER_VISITOR)->assertNotFound();
    $this->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages", ['X-Visitor-Id' => OTHER_VISITOR])->assertNotFound();

    expect(Conversation::withoutGlobalScopes()->first()->message_count)->toBe(2);
});

test('una conversación no se puede usar a través de otro agente, aunque sea de la misma empresa', function () {
    [, $organization] = userWithOrganization();
    $a = widgetAgent($organization, ['name' => 'A']);
    $b = widgetAgent($organization, ['name' => 'B']);
    $id = sendWidget($a, 'hola')->json('conversation_id');

    sendWidget($b, 'hola', $id)->assertNotFound();
    $this->getJson("/api/widget/{$b->public_id}/conversations/{$id}/messages", ['X-Visitor-Id' => VISITOR])->assertNotFound();
});

test('una conversación de otra empresa no es accesible ni con el visitor_id correcto', function () {
    [, $orgA] = userWithOrganization();
    [, $orgB] = userWithOrganization();
    $agentA = widgetAgent($orgA);
    $agentB = widgetAgent($orgB);
    $id = sendWidget($agentA, 'hola')->json('conversation_id');

    sendWidget($agentB, 'hola', $id)->assertNotFound();
    $this->getJson("/api/widget/{$agentB->public_id}/conversations/{$id}/messages", ['X-Visitor-Id' => VISITOR])->assertNotFound();
});

test('el tenant sale del agente aunque haya quedado otra empresa fijada de antes', function () {
    [, $orgA] = userWithOrganization();
    [, $orgB] = userWithOrganization();
    $agentA = agentWithKnowledge($orgA, ['Precios' => 'El corte cuesta 25.000 pesos.']);
    seedKnowledge($orgB, ['Secreto' => 'El corte cuesta 99.999 pesos, margen secreto.']);
    app(CurrentOrganization::class)->set($orgB); // estado "sucio" previo

    $response = sendWidget($agentA, 'cuánto cuesta el corte')->assertCreated();

    expect($response->json('message.content'))->toContain('25.000')->not->toContain('99.999')
        ->and(Conversation::withoutGlobalScopes()->first()->organization_id)->toBe($orgA->id)
        ->and(Contact::withoutGlobalScopes()->first()->organization_id)->toBe($orgA->id);
});

test('el historial del widget devuelve los mensajes en orden al visitante dueño', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $id = sendWidget($agent, 'primero')->json('conversation_id');
    sendWidget($agent, 'segundo', $id);

    $messages = $this->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages", ['X-Visitor-Id' => VISITOR])
        ->assertOk()->json('messages');

    expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($messages[0]['content'])->toBe('primero')
        ->and(array_keys($messages[0]))->toBe(['id', 'role', 'content', 'created_at']);

    $this->getJson("/api/widget/{$agent->public_id}/conversations/{$id}/messages")->assertUnprocessable();
    $this->getJson("/api/widget/{$agent->public_id}/conversations/no-es-uuid/messages", ['X-Visitor-Id' => VISITOR])->assertNotFound();
});

// ---------- público: sin sesión, CORS y límites ----------

test('el widget no usa cookies: no se establece ninguna sesión', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    $config = $this->getJson("/api/widget/{$agent->public_id}/config");
    $message = sendWidget($agent, 'hola');

    expect($config->headers->getCookies())->toBe([])->and($message->headers->getCookies())->toBe([]);
});

test('funciona desde el origen del propio panel sin exigir token CSRF', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    // Sanctum trata localhost:3000 como "stateful" en la API normal; el widget no debe verse afectado.
    $this->postJson("/api/widget/{$agent->public_id}/messages", ['message' => 'hola'], [
        'X-Visitor-Id' => VISITOR, 'Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/agents/1',
    ])->assertCreated();
});

test('CORS abierto para cualquier sitio, sin credenciales, en las rutas del widget', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    $preflight = $this->call('OPTIONS', "/api/widget/{$agent->public_id}/messages", server: [
        'HTTP_ORIGIN' => 'https://cliente.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-visitor-id',
    ]);
    expect($preflight->getStatusCode())->toBe(204)
        ->and($preflight->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and(strtolower((string) $preflight->headers->get('Access-Control-Allow-Headers')))->toContain('x-visitor-id')
        ->and($preflight->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    $actual = $this->getJson("/api/widget/{$agent->public_id}/config", ['Origin' => 'https://cliente.example']);
    expect($actual->headers->get('Access-Control-Allow-Origin'))->toBe('*');

    // Los errores también llevan CORS: si no, el navegador del visitante no podría leer el mensaje.
    $error = $this->postJson("/api/widget/{$agent->public_id}/messages", [], ['Origin' => 'https://cliente.example', 'X-Visitor-Id' => VISITOR]);
    expect($error->getStatusCode())->toBe(422)->and($error->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

test('la API del panel NO se abre a otros orígenes', function () {
    $response = $this->call('OPTIONS', '/api/agents', server: [
        'HTTP_ORIGIN' => 'https://malo.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    $get = $this->getJson('/api/agents', ['Origin' => 'https://malo.example']);
    expect($get->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

test('un visitante no puede mandar más de 8 mensajes por minuto', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    $id = null;

    foreach (range(1, 8) as $i) {
        $id = sendWidget($agent, "m$i", $id)->assertCreated()->json('conversation_id');
    }

    sendWidget($agent, 'uno más', $id)->assertStatus(429)->assertHeader('Retry-After');
});

test('el límite de un visitante no bloquea a otro (aunque compartan IP)', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    foreach (range(1, 8) as $i) {
        sendWidget($agent, "m$i")->assertCreated();
    }
    sendWidget($agent, 'bloqueado')->assertStatus(429);

    sendWidget($agent, 'hola', visitor: OTHER_VISITOR)->assertCreated();
});

test('el límite por IP y agente frena a quien rota de visitor_id', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);

    // Cada petición con un visitor_id nuevo esquiva el límite por visitante, pero no el de IP (30/min).
    foreach (range(1, 30) as $i) {
        sendWidget($agent, 'hola', visitor: (string) Str::uuid())->assertCreated();
    }

    sendWidget($agent, 'hola', visitor: (string) Str::uuid())->assertStatus(429);
});

function sendFromIp(Agent $agent, string $forwardedFor)
{
    return test()->postJson(
        "/api/widget/{$agent->public_id}/messages",
        ['message' => 'hola'],
        ['X-Visitor-Id' => (string) Str::uuid(), 'X-Forwarded-For' => $forwardedFor],
    );
}

test('sin proxies de confianza, falsear X-Forwarded-For no esquiva el límite por IP', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    config(['trustedproxy.proxies' => null]);

    foreach (range(1, 30) as $i) {
        sendFromIp($agent, "203.0.113.$i")->assertCreated(); // cada una "desde otra IP", pero es la misma
    }

    sendFromIp($agent, '198.51.100.99')->assertStatus(429);
});

test('con proxies de confianza cada IP real tiene su propio límite', function () {
    [, $organization] = userWithOrganization();
    $agent = widgetAgent($organization);
    config(['trustedproxy.proxies' => '127.0.0.1']); // el "proxy" de los tests llega desde 127.0.0.1

    foreach (range(1, 30) as $i) {
        sendFromIp($agent, '203.0.113.10')->assertCreated();
    }

    sendFromIp($agent, '203.0.113.10')->assertStatus(429);
    sendFromIp($agent, '203.0.113.11')->assertCreated(); // otro visitante real, otra IP
});
