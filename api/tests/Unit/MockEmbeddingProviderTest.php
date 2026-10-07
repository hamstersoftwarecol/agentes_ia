<?php

use App\Services\AI\MockEmbeddingProvider;
use Tests\TestCase;

uses(TestCase::class);

function cosine(array $a, array $b): float
{
    return array_sum(array_map(fn ($x, $y) => $x * $y, $a, $b));
}

test('devuelve un vector unitario por texto con la dimensión configurada', function () {
    $response = (new MockEmbeddingProvider)->embed(['precio del corte', 'horarios de atención']);

    expect($response->vectors)->toHaveCount(2)
        ->and($response->vectors[0])->toHaveCount(config('ai.embeddings.dimensions'))
        ->and(cosine($response->vectors[0], $response->vectors[0]))->toEqualWithDelta(1.0, 1e-9)
        ->and($response->model)->toBe('mock-hash-1');
});

test('es determinista', function () {
    $provider = new MockEmbeddingProvider;

    expect($provider->embed(['hola mundo'])->vectors)->toBe($provider->embed(['hola mundo'])->vectors);
});

test('textos con palabras en común quedan más cerca que textos distintos', function () {
    [$query, $related, $unrelated] = (new MockEmbeddingProvider)->embed([
        '¿Cuánto cuesta el corte de cabello?',
        'El precio del corte de cabello es 25.000 pesos',
        'Estamos ubicados en la calle 10 con carrera 5',
    ])->vectors;

    expect(cosine($query, $related))->toBeGreaterThan(cosine($query, $unrelated));
});

test('ignora mayúsculas, tildes y plurales simples', function () {
    [$a, $b] = (new MockEmbeddingProvider)->embed(['PRECIOS Canción', 'precio cancion'])->vectors;

    expect(cosine($a, $b))->toEqualWithDelta(1.0, 1e-9);
});

test('un texto sin palabras no produce NaN', function () {
    $vector = (new MockEmbeddingProvider)->embed(['¿¿??!!'])->vectors[0];

    expect(is_nan(array_sum($vector)))->toBeFalse();
});
