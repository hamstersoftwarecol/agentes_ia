<?php

use App\Services\Billing\AiPricing;
use Tests\TestCase;

uses(TestCase::class);

test('el costo en micro-USD es tokens × USD por millón', function () {
    // Haiku 4.5: $1 / $5 por millón → 1.000 tokens de entrada = 1.000 µUSD; 100 de salida = 500 µUSD.
    expect(AiPricing::microUsd('claude-haiku-4-5', 1000, 100))->toBe(1500)
        ->and(AiPricing::microUsd('claude-opus-5-5', 1_000_000, 1_000_000))->toBe(24_000_000); // $4 + $20
});

test('un modelo desconocido o el simulado no cuestan nada', function () {
    expect(AiPricing::microUsd('mock-1', 5000, 5000))->toBe(0)
        ->and(AiPricing::microUsd(null, 5000, 5000))->toBe(0)
        ->and(AiPricing::microUsd('modelo-inventado', 5000, 5000))->toBe(0);
});

test('los embeddings solo cobran la entrada', function () {
    expect(AiPricing::microUsd('text-embedding-3-small', 1_000_000, 0))->toBe(20_000);
});
