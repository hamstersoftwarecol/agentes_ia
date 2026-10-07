<?php

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Contracts\AIProvider;

/** Chat con Claude mediante el SDK oficial de Anthropic. */
class AnthropicProvider implements AIProvider
{
    public function __construct(private readonly Client $client) {}

    public function chat(array $messages, ?string $system = null, ?array $jsonSchema = null): ChatResponse
    {
        $config = config('ai.providers.anthropic');

        if (empty($config['key'])) {
            throw new AIProviderException('ANTHROPIC_API_KEY no está configurada.');
        }

        $params = [
            'model' => $config['model'],
            'maxTokens' => (int) $config['max_tokens'],
            'messages' => array_map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content], $messages),
            // Para atención al cliente apoyada en RAG basta poco razonamiento: más rápido y barato.
            'outputConfig' => ['effort' => $config['effort']],
        ];
        if ($system) {
            $params['system'] = $system;
        }
        if ($jsonSchema) {
            // Salida estructurada: la respuesta es un único bloque de texto con JSON que cumple el esquema.
            $params['outputConfig']['format'] = ['type' => 'json_schema', 'schema' => $jsonSchema];
        }

        try {
            $response = $config['refusal_fallback']
                // Si los clasificadores de seguridad rechazan la petición, la API reintenta con otro modelo.
                ? $this->client->beta->messages->create(...$params, betas: ['server-side-fallback-2026-07-01'], fallbacks: 'default')
                : $this->client->messages->create(...$params);
        } catch (RateLimitException|APIConnectionException|InternalServerException $e) {
            throw new AIProviderException('Anthropic no disponible: '.$e->getMessage(), retryable: true, previous: $e);
        } catch (APIStatusException $e) {
            throw new AIProviderException("Anthropic respondió {$e->status} ({$e->type?->value}): ".$e->getMessage(), previous: $e);
        }

        if ($response->stopReason === 'refusal') {
            throw new AIProviderException('Anthropic rechazó la petición (refusal).');
        }

        // `content` mezcla bloques de texto y de razonamiento: solo interesa el texto.
        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new AIProviderException('Anthropic devolvió una respuesta sin texto.');
        }

        return new ChatResponse(
            content: trim($text),
            model: $response->model,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
        );
    }
}
