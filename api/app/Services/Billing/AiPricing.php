<?php

namespace App\Services\Billing;

class AiPricing
{
    /**
     * Costo estimado en micro-USD. El precio se expresa en USD por millón de tokens, que es exactamente
     * "micro-USD por token": no hace falta convertir. Un modelo desconocido (p. ej. el simulado) cuesta 0.
     */
    public static function microUsd(?string $model, int $inputTokens, int $outputTokens): int
    {
        $price = config("ai.pricing.{$model}");
        if (! $price) {
            return 0;
        }

        return (int) round($inputTokens * $price['input'] + $outputTokens * $price['output']);
    }
}
