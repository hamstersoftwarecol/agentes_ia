<?php

use App\Models\Document;
use Illuminate\Support\Facades\DB;

function markChunksAs(string $model): void
{
    DB::table('document_chunks')->update(['embedding_model' => $model]);
}

test('la búsqueda ignora fragmentos generados con otro modelo de embeddings', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, ['Precios' => 'El corte cuesta 25.000 pesos.']);

    $found = fn () => $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'cuánto cuesta el corte'])->json();
    expect($found())->toHaveCount(1);

    markChunksAs('modelo-viejo'); // como si se hubiera cambiado de proveedor

    expect($found())->toBe([]);
});

test('knowledge:reindex --stale reprocesa solo los documentos con otro modelo', function () {
    [$user, $organization] = userWithOrganization();
    $kb = seedKnowledge($organization, ['Viejo' => 'El corte cuesta 25.000 pesos.', 'Nuevo' => 'El afeitado cuesta 18.000 pesos.']);
    $old = Document::withoutGlobalScopes()->where('title', 'Viejo')->first();
    DB::table('document_chunks')->where('document_id', $old->id)->update(['embedding_model' => 'modelo-viejo']);
    $untouched = Document::withoutGlobalScopes()->where('title', 'Nuevo')->first()->processed_at;

    $this->artisan('knowledge:reindex --stale')->expectsOutputToContain('1 documento(s)')->assertSuccessful();

    expect(DB::table('document_chunks')->where('embedding_model', 'modelo-viejo')->count())->toBe(0)
        ->and(Document::withoutGlobalScopes()->find($old->id)->status->value)->toBe('ready')
        ->and(Document::withoutGlobalScopes()->where('title', 'Nuevo')->first()->processed_at->equalTo($untouched))->toBeTrue();

    $results = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/search", ['query' => 'cuánto cuesta el corte'])->json();
    expect($results[0]['title'])->toBe('Viejo');
});

test('knowledge:reindex sin opciones reprocesa todo y --organization limita a una empresa', function () {
    [, $orgA] = userWithOrganization();
    [, $orgB] = userWithOrganization();
    seedKnowledge($orgA, ['A1' => 'texto a uno', 'A2' => 'texto a dos']);
    seedKnowledge($orgB, ['B1' => 'texto b uno']);
    markChunksAs('modelo-viejo');

    $this->artisan("knowledge:reindex --organization={$orgA->id}")->expectsOutputToContain('2 documento(s)');
    expect(DB::table('document_chunks')->where('embedding_model', 'modelo-viejo')->count())->toBe(1);

    $this->artisan('knowledge:reindex')->expectsOutputToContain('3 documento(s)');
    expect(DB::table('document_chunks')->where('embedding_model', 'modelo-viejo')->count())->toBe(0);
});
