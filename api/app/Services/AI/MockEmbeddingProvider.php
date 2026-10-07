<?php

namespace App\Services\AI;

use App\Contracts\EmbeddingProvider;
use Illuminate\Support\Str;

/**
 * Embeddings falsos pero útiles para desarrollar sin API keys: "feature hashing"
 * de palabras. Textos con palabras en común quedan cerca (coseno alto), pero no
 * entiende sinónimos ni contexto; para eso hace falta un proveedor real.
 */
class MockEmbeddingProvider implements EmbeddingProvider
{
    public const MODEL = 'mock-hash-1';

    public function model(): string
    {
        return self::MODEL;
    }

    public function embed(array $texts): EmbeddingResponse
    {
        $dimensions = (int) config('ai.embeddings.dimensions');

        return new EmbeddingResponse(
            vectors: array_map(fn (string $text) => $this->vectorize($text, $dimensions), $texts),
            model: self::MODEL,
            tokens: (int) ceil(array_sum(array_map('mb_strlen', $texts)) / 4),
        );
    }

    /** @return list<float> */
    private function vectorize(string $text, int $dimensions): array
    {
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($this->tokens($text) as $token) {
            $vector[crc32($token) % $dimensions] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));
        if ($norm == 0.0) {
            $vector[0] = 1.0; // texto sin palabras: vector unitario cualquiera, nunca división por cero

            return $vector;
        }

        return array_map(fn ($v) => $v / $norm, $vector);
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_map(
            // Quita la "s" final para que "precio" y "precios" coincidan.
            fn (string $w) => strlen($w) > 3 ? rtrim($w, 's') : $w,
            array_filter($words, fn (string $w) => strlen($w) > 1),
        ));
    }
}
