<?php

use App\Contracts\AIProvider;
use App\Services\AI\ChatMessage;
use App\Services\AI\MockAIProvider;
use Tests\TestCase;

uses(TestCase::class);

test('por defecto el contrato se resuelve al proveedor mock', function () {
    expect(app(AIProvider::class))->toBeInstanceOf(MockAIProvider::class);
});

test('el mock responde de forma determinista y reporta tokens', function () {
    $response = app(AIProvider::class)->chat([ChatMessage::user('¿Cuánto cuesta un corte?')], 'Eres Sofía.');

    expect($response->content)->toContain('¿Cuánto cuesta un corte?')
        ->and($response->inputTokens)->toBeGreaterThan(0)
        ->and($response->outputTokens)->toBeGreaterThan(0);
});

test('un proveedor desconocido falla con un error claro', function () {
    config(['ai.default' => 'no-existe']);

    app(AIProvider::class);
})->throws(InvalidArgumentException::class);
