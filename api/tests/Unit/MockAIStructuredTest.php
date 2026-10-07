<?php

use App\Services\Agents\AgentTurnParser;
use App\Services\AI\ChatMessage;
use App\Services\AI\MockAIProvider;
use Tests\TestCase;

uses(TestCase::class);

function mockTurn(array $userMessages, ?string $system = null): array
{
    $messages = collect($userMessages)->map(fn ($t) => ChatMessage::user($t))->all();
    $response = (new MockAIProvider)->chat($messages, $system, AgentTurnParser::schema());

    return json_decode($response->content, true);
}

test('con esquema devuelve siempre un JSON con reply, action y lead', function () {
    $turn = mockTurn(['hola']);

    expect($turn)->toHaveKeys(['reply', 'action', 'lead'])->and($turn['action'])->toBe('none')->and($turn['lead'])->toBeNull();
});

test('pasa a una persona cuando el cliente la pide', function (string $text) {
    expect(mockTurn([$text])['action'])->toBe('request_human');
})->with([
    'persona' => 'Quiero hablar con una persona',
    'humano' => 'necesito un humano por favor',
    'asesor' => 'me pasas con un asesor',
    'asesora' => 'prefiero una asesora',
]);

test('con intención de compra y sin datos pide nombre y contacto, sin crear lead', function () {
    $turn = mockTurn(['Quiero contratar el plan empresarial']);

    expect($turn['reply'])->toContain('cómo te llamas')->and($turn['lead'])->toBeNull()->and($turn['action'])->toBe('none');
});

test('extrae el lead de lo que el cliente fue diciendo en la conversación', function () {
    $turn = mockTurn(['Quiero contratar el plan empresarial', 'Me llamo Alejandro Pérez, mi WhatsApp es 300 123 4567']);

    expect($turn['lead']['name'])->toBe('Alejandro Pérez')
        ->and($turn['lead']['phone'])->toBe('300 123 4567')
        ->and($turn['lead']['interest'])->toBe('Quiero contratar el plan empresarial')
        ->and($turn['reply'])->toContain('Gracias Alejandro Pérez');
});

test('también reconoce el correo', function () {
    $turn = mockTurn(['Me interesa una cotización, escríbeme a ale@example.com']);

    expect($turn['lead']['email'])->toBe('ale@example.com');
});

test('sin esquema sigue respondiendo en texto plano', function () {
    $response = (new MockAIProvider)->chat([ChatMessage::user('hola')]);

    expect($response->content)->toContain('Recibí tu mensaje')->and(json_decode($response->content))->toBeNull();
});
