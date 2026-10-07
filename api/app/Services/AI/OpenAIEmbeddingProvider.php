<?php

namespace App\Services\AI;

use App\Contracts\EmbeddingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Embeddings de OpenAI (o de cualquier API compatible vía OPENAI_BASE_URL). */
class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    public function model(): string
    {
        return config('ai.embeddings.providers.openai.model');
    }

    public function embed(array $texts): EmbeddingResponse
    {
        $config = config('ai.embeddings.providers.openai');

        if (empty($config['key'])) {
            throw new AIProviderException('OPENAI_API_KEY no está configurada.');
        }

        $payload = ['model' => $config['model'], 'input' => array_values($texts), 'encoding_format' => 'float'];
        // Solo los modelos text-embedding-3-* permiten fijar la dimensión; así 3-large cabe en vector(1536).
        if (Str::startsWith($config['model'], 'text-embedding-3')) {
            $payload['dimensions'] = (int) config('ai.embeddings.dimensions');
        }

        try {
            $response = Http::withToken($config['key'])
                ->acceptJson()
                ->timeout(30)
                ->retry(
                    2,
                    500,
                    fn ($e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
                    throw: false,
                )
                ->post(rtrim($config['base_url'], '/').'/embeddings', $payload);
        } catch (ConnectionException $e) {
            throw new AIProviderException('OpenAI no disponible: '.$e->getMessage(), retryable: true, previous: $e);
        }

        if ($response->failed()) {
            throw new AIProviderException(
                "OpenAI respondió {$response->status()}: ".Str::limit((string) $response->json('error.message', $response->body()), 200),
                retryable: in_array($response->status(), [429, 500, 502, 503, 504], true),
            );
        }

        $data = collect($response->json('data', []))->sortBy('index')->values();
        if ($data->count() !== count($texts)) {
            throw new AIProviderException('OpenAI devolvió una cantidad inesperada de embeddings.');
        }

        return new EmbeddingResponse(
            vectors: $data->map(fn (array $row) => array_map('floatval', $row['embedding']))->all(),
            model: $config['model'],
            tokens: (int) $response->json('usage.total_tokens', 0),
        );
    }
}
