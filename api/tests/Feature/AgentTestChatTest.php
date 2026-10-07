<?php

use App\Models\Agent;
use App\Support\CurrentOrganization;

test('el agente responde usando el conocimiento recuperado y devuelve las fuentes', function () {
    [$user, $organization] = userWithOrganization();
    $agent = agentWithKnowledge($organization, [
        'Precios' => 'El corte de cabello cuesta 25.000 pesos.',
        'Ubicación' => 'Estamos en la calle 10 con carrera 5.',
    ]);

    $response = $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", [
        'messages' => [['role' => 'user', 'content' => '¿Cuánto cuesta el corte de cabello?']],
    ])->assertOk();

    expect($response->json('reply'))->toContain('25.000')
        ->and($response->json('sources.0.title'))->toBe('Precios')
        ->and($response->json('sources.0.similarity'))->toBeGreaterThan(0.3)
        ->and($response->json('model'))->toBe('mock-1')
        ->and($response->json('usage.input_tokens'))->toBeGreaterThan(0);
});

test('el prompt incluye identidad, reglas, datos de la empresa y el conocimiento', function () {
    [$user, $organization] = userWithOrganization();
    $organization->update(['name' => 'Barbería XYZ', 'phone' => '3001234567']);
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.'], [
        'role' => 'Asesora comercial', 'goal' => 'Conseguir reservas.', 'tone' => 'formal', 'language' => 'en',
        'instructions' => 'Nunca ofrezcas descuentos.',
    ]);

    $prompt = $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", [
        'messages' => [['role' => 'user', 'content' => 'precio del corte']],
    ])->json('system_prompt');

    expect($prompt)
        ->toContain('Eres Sofía, Asesora comercial de Barbería XYZ.')
        ->toContain('Tu objetivo: Conseguir reservas.')
        ->toContain('formal y respetuoso')
        ->toContain('responde siempre en inglés')
        ->toContain('No inventes precios')
        ->toContain('Nunca ofrezcas descuentos.')
        ->toContain('Teléfono: 3001234567')
        ->toContain('Lunes: 09:00–18:00')
        ->toContain('Sábado: cerrado')
        ->toContain("<conocimiento>\n[1] Precios\nEl corte cuesta 25.000 pesos.");
});

test('si no hay información relacionada lo indica y no inventa', function () {
    [$user, $organization] = userWithOrganization();
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $response = $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", [
        'messages' => [['role' => 'user', 'content' => 'zzz qqq']],
    ])->assertOk();

    expect($response->json('sources'))->toBe([])
        ->and($response->json('reply'))->toContain('No tengo información')
        ->and($response->json('system_prompt'))->toContain('No se encontró información relacionada');
});

test('un agente sin base de conocimiento también responde', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create();

    $response = $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", [
        'messages' => [['role' => 'user', 'content' => 'hola']],
    ])->assertOk();

    expect($response->json('system_prompt'))->toContain('no tiene una base de conocimiento asignada');
});

test('se puede probar un agente desactivado', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create(['is_active' => false]);

    $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", ['messages' => [['role' => 'user', 'content' => 'hola']]])->assertOk();
});

test('el cliente puede mandar el historial de la conversación', function () {
    [$user, $organization] = userWithOrganization();
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", ['messages' => [
        ['role' => 'user', 'content' => 'hola'],
        ['role' => 'assistant', 'content' => '¡Hola! ¿En qué te ayudo?'],
        ['role' => 'user', 'content' => 'cuánto cuesta el corte'],
    ]])->assertOk()->assertJsonPath('sources.0.title', 'Precios');
});

test('valida el formato de los mensajes', function () {
    [$user, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $agent = Agent::factory()->create();
    $url = "/api/agents/{$agent->id}/test-chat";

    $this->actingAs($user)->postJson($url, [])->assertJsonValidationErrors('messages');
    $this->actingAs($user)->postJson($url, ['messages' => [['role' => 'system', 'content' => 'x']]])->assertJsonValidationErrors('messages.0.role');
    $this->actingAs($user)->postJson($url, ['messages' => [['role' => 'user', 'content' => str_repeat('a', 2001)]]])->assertJsonValidationErrors('messages.0.content');
    $this->actingAs($user)->postJson($url, ['messages' => [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']]])
        ->assertJsonValidationErrors('messages');
    $this->actingAs($user)->postJson($url, ['messages' => array_fill(0, 21, ['role' => 'user', 'content' => 'x'])])->assertJsonValidationErrors('messages');
});

test('no se puede probar el agente de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    app(CurrentOrganization::class)->set($other);
    $foreign = Agent::factory()->create();

    $this->actingAs($user)->postJson("/api/agents/{$foreign->id}/test-chat", ['messages' => [['role' => 'user', 'content' => 'hola']]])->assertNotFound();
});

test('el conocimiento de otra empresa nunca llega al prompt', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    seedKnowledge($other, ['Secreto' => 'El corte cuesta 99.999 pesos, margen secreto.']);
    $agent = agentWithKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $response = $this->actingAs($user)->postJson("/api/agents/{$agent->id}/test-chat", [
        'messages' => [['role' => 'user', 'content' => 'cuánto cuesta el corte']],
    ])->json();

    expect($response['system_prompt'])->not->toContain('99.999')->not->toContain('secreto')
        ->and($response['reply'])->not->toContain('99.999');
});
