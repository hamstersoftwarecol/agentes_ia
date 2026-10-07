<?php

namespace App\Services\AI;

final readonly class EmbeddingResponse
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $tokens = 0,
    ) {}
}
