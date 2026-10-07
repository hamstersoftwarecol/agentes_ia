<?php

use App\Services\AI\AnthropicProvider;
use App\Services\AI\MockAIProvider;
use App\Services\AI\MockEmbeddingProvider;

return [

    /*
    | Proveedor de chat por defecto. Valores: ver `providers`.
    | Mientras no haya API keys se usa "mock".
    */
    'default' => env('AI_PROVIDER', 'mock'),

    'providers' => [
        'mock' => [
            'driver' => MockAIProvider::class,
        ],

        'anthropic' => [
            'driver' => AnthropicProvider::class,
            'key' => env('ANTHROPIC_API_KEY'),
            // Modelo del chat. Opus 5.5 es el más capaz; para bajar el costo por mensaje usa uno más
            // económico (p. ej. claude-sonnet-5-5 o claude-haiku-4-5). Es una decisión de margen, no técnica.
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
            // Esfuerzo de razonamiento: low | medium | high | xhigh | max.
            'effort' => env('ANTHROPIC_EFFORT', 'low'),
            'max_tokens' => 1024,
            // Si los clasificadores de seguridad rechazan una petición, la API reintenta con otro modelo.
            'refusal_fallback' => (bool) env('ANTHROPIC_REFUSAL_FALLBACK', true),
        ],

        // Pendientes: 'openai' (chat) y 'gemini'.
    ],

    /*
    | Embeddings (vectores para el RAG). Aparte del chat porque no todos los
    | proveedores de chat ofrecen embeddings (Anthropic no).
    |
    | `dimensions` debe coincidir con la columna vector(N) de document_chunks.
    | Cambiarla exige una migración y reindexar todos los documentos.
    */
    'embeddings' => [
        'default' => env('AI_EMBEDDINGS_PROVIDER', 'mock'),
        'dimensions' => 1536,

        'providers' => [
            'mock' => [
                'driver' => MockEmbeddingProvider::class,
            ],
        ],
    ],

    /*
    | Precios para estimar el costo de cada llamada (USD por millón de tokens). Sirven para el margen del
    | superadmin; la factura real es la del proveedor. Un modelo que no está aquí cuenta costo 0.
    */
    'pricing' => [
        'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00],
        'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.00],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.00],
    ],

];
