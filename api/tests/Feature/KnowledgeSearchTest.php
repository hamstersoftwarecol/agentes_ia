<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;

test('la búsqueda devuelve primero el fragmento más relevante', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, [
        'Precios' => 'El corte de cabello cuesta 25.000 pesos y el afeitado 18.000 pesos.',
        'Ubicación' => 'Estamos ubicados en la calle 10 con carrera 5, frente al parque.',
        'Horarios' => 'Atendemos de lunes a sábado de nueve de la mañana a siete de la noche.',
    ]);

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => '¿Cuánto cuesta el corte de cabello?'])
        ->assertOk()->json();

    expect($results[0]['title'])->toBe('Precios')
        ->and($results[0]['content'])->toContain('25.000')
        ->and($results[0]['similarity'])->toBeGreaterThan(0.3);

    $dondeEstan = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'en qué calle están ubicados'])->json();
    expect($dondeEstan[0]['title'])->toBe('Ubicación');
});

test('los resultados vienen ordenados por similitud descendente', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, [
        'A' => 'corte de cabello precio', 'B' => 'precio de la barba', 'C' => 'cabello largo corte precio barba',
    ]);

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'precio corte cabello'])->json();

    $similarities = array_column($results, 'similarity');
    $sorted = $similarities;
    rsort($sorted);
    expect($similarities)->toBe($sorted);
});

test('solo busca dentro de la base de conocimiento indicada', function () {
    [$user, $organization] = userWithOrganization();
    $mine = seedKnowledge($organization, ['Mía' => 'El corte cuesta 25.000 pesos.'], 'Mía');
    seedKnowledge($organization, ['Otra base' => 'El corte cuesta 99.000 pesos en la sede norte.'], 'Otra');

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$mine->id}/search", ['query' => 'cuánto cuesta el corte'])->json();

    expect(array_column($results, 'title'))->toBe(['Mía']);
});

test('nunca devuelve fragmentos de otra empresa', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    $mine = seedKnowledge($organization, ['Propio' => 'Vendemos pomadas para el cabello.']);
    $foreign = seedKnowledge($other, ['Secreto ajeno' => 'Vendemos pomadas para el cabello. Margen secreto 80%.']);

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$foreign->id}/search", ['query' => 'pomadas'])->assertNotFound();

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$mine->id}/search", ['query' => 'pomadas'])->json();
    expect(array_column($results, 'title'))->toBe(['Propio']);
});

test('ignora los documentos que aún no están listos', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, ['Listo' => 'El corte cuesta 25.000 pesos.']);
    $pending = Document::factory()->create(['knowledge_base_id' => $kb->id, 'title' => 'Pendiente', 'content' => 'El corte cuesta 1 peso.']);
    // Un fragmento huérfano de un documento que se está reprocesando no debe aparecer.
    ProcessDocument::dispatchSync($pending->id);
    $pending->forceFill(['status' => 'processing'])->save();

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'cuánto cuesta el corte'])->json();

    expect(array_column($results, 'title'))->toBe(['Listo']);
});

test('sin coincidencias suficientes devuelve una lista vacía', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'zzz qqq xxx'])->assertOk()->assertExactJson([]);
});

test('valida la consulta', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, []);

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => ''])->assertJsonValidationErrors('query');
    $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => str_repeat('a', 501)])->assertJsonValidationErrors('query');
});
