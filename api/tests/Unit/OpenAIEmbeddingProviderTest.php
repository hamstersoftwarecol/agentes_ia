<?php

use App\Services\AI\AIProviderException;
use App\Services\AI\OpenAIEmbeddingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['ai.embeddings.providers.openai' => [
        'key' => 'sk-test', 'model' => 'text-embedding-3-small', 'base_url' => 'https://api.openai.com/v1',
    ]]);
    Sleep::fake();
});

function openAiEmbeddings(array $vectors, int $tokens = 12): array
{
    return [
        'object' => 'list',
        'data' => collect($vectors)->map(fn ($v, $i) => ['object' => 'embedding', 'index' => $i, 'embedding' => $v])->all(),
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => $tokens, 'total_tokens' => $tokens],
    ];
}

test('pide los embeddings con el modelo, las dimensiones y la clave configurados', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiEmbeddings([[0.1, 0.2], [0.3, 0.4]]))]);

    $response = (new OpenAIEmbeddingProvider)->embed(['uno', 'dos']);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.openai.com/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'text-embedding-3-small'
            && $request['input'] === ['uno', 'dos']
            && $request['dimensions'] === 1536
            && $request['encoding_format'] === 'float';
    });
    expect($response->vectors)->toBe([[0.1, 0.2], [0.3, 0.4]])
        ->and($response->model)->toBe('text-embedding-3-small')
        ->and($response->tokens)->toBe(12);
});

test('devuelve los vectores en el orden de los textos aunque la API los reordene', function () {
    $payload = openAiEmbeddings([[1.0], [2.0], [3.0]]);
    $payload['data'] = array_reverse($payload['data']);
    Http::fake(['*' => Http::response($payload)]);

    expect((new OpenAIEmbeddingProvider)->embed(['a', 'b', 'c'])->vectors)->toBe([[1.0], [2.0], [3.0]]);
});

test('no envía dimensions a modelos que no lo soportan', function () {
    config(['ai.embeddings.providers.openai.model' => 'text-embedding-ada-002']);
    Http::fake(['*' => Http::response(openAiEmbeddings([[0.1]]))]);

    (new OpenAIEmbeddingProvider)->embed(['x']);

    Http::assertSent(fn (Request $request) => ! array_key_exists('dimensions', $request->data()));
});

test('respeta una URL base compatible con OpenAI', function () {
    config(['ai.embeddings.providers.openai.base_url' => 'https://gateway.interno/v1/']);
    Http::fake(['gateway.interno/*' => Http::response(openAiEmbeddings([[0.1]]))]);

    (new OpenAIEmbeddingProvider)->embed(['x']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.interno/v1/embeddings');
});

test('expone el modelo configurado', function () {
    expect((new OpenAIEmbeddingProvider)->model())->toBe('text-embedding-3-small');
});

test('sin API key falla antes de llamar a la red', function () {
    config(['ai.embeddings.providers.openai.key' => null]);
    Http::fake();

    try {
        (new OpenAIEmbeddingProvider)->embed(['x']);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->getMessage())->toContain('OPENAI_API_KEY');
        Http::assertNothingSent();
    }
});

test('una clave inválida no se reintenta y no es reintentable', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

    try {
        (new OpenAIEmbeddingProvider)->embed(['x']);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('401')->not->toContain('sk-test');
        Http::assertSentCount(1);
    }
});

test('un límite de uso se reintenta y al final se marca como reintentable', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit']], 429)]);

    try {
        (new OpenAIEmbeddingProvider)->embed(['x']);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->retryable)->toBeTrue();
        Http::assertSentCount(2); // retry(2) = 2 intentos; el job (tries=3) reintenta por encima de esto
    }
});

test('se recupera si el reintento tiene éxito', function () {
    Http::fake(['*' => Http::sequence()->push([], 503)->push(openAiEmbeddings([[0.5]]))]);

    expect((new OpenAIEmbeddingProvider)->embed(['x'])->vectors)->toBe([[0.5]]);
});

test('una cantidad de vectores inesperada es un error', function () {
    Http::fake(['*' => Http::response(openAiEmbeddings([[0.1]]))]);

    (new OpenAIEmbeddingProvider)->embed(['uno', 'dos']);
})->throws(AIProviderException::class, 'cantidad inesperada');

test('un error de conexión se marca reintentable', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    try {
        (new OpenAIEmbeddingProvider)->embed(['x']);
        $this->fail('Debió lanzar excepción');
    } catch (AIProviderException $e) {
        expect($e->retryable)->toBeTrue();
    }
});
