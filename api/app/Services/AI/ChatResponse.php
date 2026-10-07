<?php

namespace App\Services\AI;

/** Respuesta + consumo; los tokens alimentan `usage_records` y el cálculo de margen. */
final readonly class ChatResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
