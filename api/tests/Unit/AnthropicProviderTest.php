<?php

use Anthropic\Client;
use App\Services\AI\AIProviderException;
use App\Services\AI\AnthropicProvider;
use App\Services\AI\ChatMessage;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

uses(TestCase::class);

/** Transporte HTTP falso del SDK: devuelve respuestas encoladas y guarda las peticiones. */
class FakeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param  list<ResponseInterface>  $responses */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(500, [], '{}');
    }

    public function lastBody(): array
    {
        return json_decode((string) end($this->requests)->getBody(), true);
    }
}

function claudeReply(array $overrides = []): Response
{
    return new Response(200, ['content-type' => 'application/json'], json_encode($overrides + [
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Hola, ¿en qué te ayudo?'],
        ],
        'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 321, 'output_tokens' => 45],
    ]));
}

function claudeError(int $status, string $type, string $message = 'x'): Response
{
    return new Response($status, ['content-type' => 'application/json'], json_encode([
        'type' => 'error', 'error' => ['type' => $type, 'message' => $message],
    ]));
}

function anthropicWith(FakeTransport $transport, array $config = []): AnthropicProvider
{
    config(['ai.providers.anthropic' => $config + [
        'key' => 'sk-ant-test', 'model' => 'claude-opus-5-5', 'effort' => 'low', 'max_tokens' => 1024, 'refusal_fallback' => true,
    ]]);

    return new AnthropicProvider(new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]));
}

test('envía el modelo, el sistema, el historial y el esfuerzo configurados', function () {
    $transport = new FakeTransport([claudeReply()]);

    anthropicWith($transport)->chat(
        [ChatMessage::user('hola'), ChatMessage::assistant('¡Hola!'), ChatMessage::user('¿precio?')],
        'Eres Sofía.',
    );

    $request = end($transport->requests);
    $body = $transport->lastBody();

    expect($request->getHeaderLine('x-api-key'))->toBe('sk-ant-test')
        ->and($request->getUri()->getPath())->toBe('/v1/messages')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(1024)
        ->and($body['system'])->toBe('Eres Sofía.')
        ->and($body['output_config']['effort'])->toBe('low')
        ->and($body['messages'])->toBe([
            ['role' => 'user', 'content' => 'hola'],
            ['role' => 'assistant', 'content' => '¡Hola!'],
            ['role' => 'user', 'content' => '¿precio?'],
        ])
        // Parámetros que los modelos nuevos rechazan con 400: nunca deben enviarse.
        ->and($body)->not->toHaveKeys(['temperature', 'top_p', 'top_k', 'thinking']);
});

test('por defecto pide la red de seguridad de refusal fallback', function () {
    $transport = new FakeTransport([claudeReply()]);

    anthropicWith($transport)->chat([ChatMessage::user('hola')]);

    $request = end($transport->requests);
    expect($request->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($transport->lastBody()['fallbacks'])->toBe('default');
});

test('el refusal fallback se puede desactivar', function () {
    $transport = new FakeTransport([claudeReply()]);

    anthropicWith($transport, ['refusal_fallback' => false])->chat([ChatMessage::user('hola')]);

    expect(end($transport->requests)->getHeaderLine('anthropic-beta'))->toBe('')
        ->and($transport->lastBody())->not->toHaveKey('fallbacks');
});

test('devuelve solo el texto (ignora el razonamiento) con modelo y tokens', function () {
    $response = anthropicWith(new FakeTransport([claudeReply()]))->chat([ChatMessage::user('hola')]);

    expect($response->content)->toBe('Hola, ¿en qué te ayudo?')
        ->and($response->model)->toBe('claude-opus-5-5')
        ->and($response->inputTokens)->toBe(321)
        ->and($response->outputTokens)->toBe(45);
});

test('une varios bloques de texto', function () {
    $reply = claudeReply(['content' => [['type' => 'text', 'text' => 'Uno. '], ['type' => 'text', 'text' => 'Dos.']]]);

    expect(anthropicWith(new FakeTransport([$reply]))->chat([ChatMessage::user('x')])->content)->toBe('Uno. Dos.');
});

test('un refusal se convierte en error del proveedor, no en una respuesta vacía', function () {
    $reply = claudeReply(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'x']]);

    anthropicWith(new FakeTransport([$reply]))->chat([ChatMessage::user('x')]);
})->throws(AIProviderException::class, 'refusal');

test('una respuesta sin texto es un error', function () {
    anthropicWith(new FakeTransport([claudeReply(['content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's']]])]))
        ->chat([ChatMessage::user('x')]);
})->throws(AIProviderException::class, 'sin texto');

test('sin API key falla antes de llamar a la red', function () {
    $transport = new FakeTransport([claudeReply()]);

    try {
        anthropicWith($transport, ['key' => null])->chat([ChatMessage::user('x')]);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->getMessage())->toContain('ANTHROPIC_API_KEY')->and($transport->requests)->toBe([]);
    }
});

test('los errores transitorios se marcan reintentables y los de configuración no', function (int $status, string $type, bool $retryable) {
    try {
        anthropicWith(new FakeTransport([claudeError($status, $type)]))->chat([ChatMessage::user('x')]);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->retryable)->toBe($retryable);
    }
})->with([
    'límite de uso' => [429, 'rate_limit_error', true],
    'sobrecarga' => [529, 'overloaded_error', true],
    'error del servidor' => [500, 'api_error', true],
    'clave inválida' => [401, 'authentication_error', false],
    'petición inválida' => [400, 'invalid_request_error', false],
    'sin permiso' => [403, 'permission_error', false],
]);

test('el error técnico nunca incluye la API key', function () {
    try {
        anthropicWith(new FakeTransport([claudeError(401, 'authentication_error', 'invalid x-api-key')]))->chat([ChatMessage::user('x')]);
    } catch (AIProviderException $e) {
        expect($e->getMessage())->not->toContain('sk-ant-test');
    }
});

test('con esquema pide salida estructurada JSON y sin esquema no envía formato', function () {
    $schema = ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false];
    $transport = new FakeTransport([claudeReply(), claudeReply()]);
    $provider = anthropicWith($transport);

    $provider->chat([ChatMessage::user('hola')], 'Eres Sofía.', $schema);
    $withSchema = $transport->lastBody();

    $provider->chat([ChatMessage::user('hola')], 'Eres Sofía.');
    $without = $transport->lastBody();

    expect($withSchema['output_config']['format'])->toBe(['type' => 'json_schema', 'schema' => $schema])
        ->and($withSchema['output_config']['effort'])->toBe('low') // el esfuerzo se conserva junto al formato
        ->and($without['output_config'])->not->toHaveKey('format');
});

test('devuelve el JSON del modelo tal cual para que lo interprete AgentTurnParser', function () {
    $json = json_encode(['reply' => 'Hola', 'action' => 'none', 'lead' => null]);
    $reply = claudeReply(['content' => [['type' => 'text', 'text' => $json]]]);

    $response = anthropicWith(new FakeTransport([$reply]))->chat([ChatMessage::user('x')], null, ['type' => 'object']);

    expect($response->content)->toBe($json);
});
