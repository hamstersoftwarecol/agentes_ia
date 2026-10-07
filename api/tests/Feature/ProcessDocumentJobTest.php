<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

test('el job procesa el documento sin usuario ni petición, tomando el tenant del propio documento', function () {
    [, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $kb = KnowledgeBase::factory()->create();
    $document = Document::factory()->create(['knowledge_base_id' => $kb->id, 'content' => 'Vendemos pomadas y ceras para el cabello.']);

    // Como en un worker: ningún tenant activo al empezar.
    app(CurrentOrganization::class)->set(null);
    ProcessDocument::dispatchSync($document->id);

    $document = Document::withoutGlobalScopes()->find($document->id);
    expect($document->status->value)->toBe('ready')
        ->and(DB::table('document_chunks')->where('document_id', $document->id)->where('organization_id', $organization->id)->count())->toBe(1);
});

test('si el documento ya no existe el job termina sin error', function () {
    ProcessDocument::dispatchSync(999999);

    expect(true)->toBeTrue();
});

test('un fallo inesperado deja el documento como fallido al agotar los reintentos', function () {
    [, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $kb = KnowledgeBase::factory()->create();
    $document = Document::factory()->create(['knowledge_base_id' => $kb->id, 'status' => 'processing']);

    (new ProcessDocument($document->id))->failed(new RuntimeException('API caída'));

    $document = Document::withoutGlobalScopes()->find($document->id);
    expect($document->status->value)->toBe('failed')->and($document->error)->toContain('No pudimos procesar');
});

test('un documento sin contenido indexable falla con mensaje claro', function () {
    [, $organization] = userWithOrganization();
    app(CurrentOrganization::class)->set($organization);
    $kb = KnowledgeBase::factory()->create();
    $document = Document::factory()->create(['knowledge_base_id' => $kb->id, 'content' => '   ']);

    ProcessDocument::dispatchSync($document->id);

    $document = Document::withoutGlobalScopes()->find($document->id);
    expect($document->status->value)->toBe('failed')->and($document->error)->toContain('no tiene contenido');
});
