<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use App\Contracts\EmbeddingProvider;
use InvalidArgumentException;

class AIManager
{
    public function provider(?string $name = null): AIProvider
    {
        $name ??= config('ai.default');

        return $this->resolve("ai.providers.$name.driver", $name);
    }

    public function embeddings(?string $name = null): EmbeddingProvider
    {
        $name ??= config('ai.embeddings.default');

        return $this->resolve("ai.embeddings.providers.$name.driver", $name);
    }

    private function resolve(string $configKey, string $name): object
    {
        $class = config($configKey);

        if (! $class) {
            throw new InvalidArgumentException("Proveedor de IA no configurado: [$name].");
        }

        return app($class);
    }
}
