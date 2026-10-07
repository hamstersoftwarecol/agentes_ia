<?php

use App\Enums\OrganizationRole;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

/** @return array{0: User, 1: Organization, 2: KnowledgeBase} */
function setupKnowledge(OrganizationRole $role = OrganizationRole::Owner): array
{
    [$user, $organization] = userWithOrganization($role);
    app(CurrentOrganization::class)->set($organization);

    return [$user, $organization, KnowledgeBase::factory()->create()];
}

function chunkRowsOf(Document $document): array
{
    return DB::table('document_chunks')->where('document_id', $document->id)->orderBy('position')->get()->all();
}

test('un documento de texto se procesa: fragmentos con vector y estado listo', function () {
    [$user, , $kb] = setupKnowledge();

    $response = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", [
        'type' => 'text',
        'title' => 'Servicios',
        'content' => 'Ofrecemos corte de cabello, afeitado clásico y arreglo de barba. El corte cuesta 25.000 pesos.',
    ])->assertStatus(202)->assertJsonPath('type', 'text');

    $document = Document::find($response->json('id'));
    expect($document->status->value)->toBe('ready')
        ->and($document->chunk_count)->toBe(1)
        ->and($document->processed_at)->not->toBeNull();

    $row = chunkRowsOf($document)[0];
    expect($row->embedding_model)->toBe('mock-hash-1')
        ->and($row->organization_id)->toBe($document->organization_id)
        ->and(DB::selectOne('select vector_dims(embedding) as d from document_chunks where id = ?', [$row->id])->d)->toBe(1536);
});

test('una FAQ guarda pregunta y respuesta juntas en el fragmento', function () {
    [$user, , $kb] = setupKnowledge();

    $id = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", [
        'type' => 'faq', 'title' => '¿Aceptan tarjeta?', 'content' => 'Sí, aceptamos todas las tarjetas.',
    ])->assertStatus(202)->json('id');

    $row = chunkRowsOf(Document::find($id))[0];
    expect($row->content)->toContain('Pregunta: ¿Aceptan tarjeta?')->toContain('Respuesta: Sí, aceptamos todas las tarjetas.');
});

test('un texto largo se divide en varios fragmentos', function () {
    [$user, , $kb] = setupKnowledge();
    $long = collect(range(1, 120))->map(fn ($i) => "Dato número $i sobre nuestros servicios y políticas.")->implode(' ');

    $id = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", [
        'type' => 'text', 'title' => 'Manual', 'content' => $long,
    ])->json('id');

    $document = Document::find($id);
    expect($document->chunk_count)->toBeGreaterThan(3)
        ->and(count(chunkRowsOf($document)))->toBe($document->chunk_count);
});

test('se puede subir un archivo de texto y se extrae su contenido', function () {
    [$user, , $kb] = setupKnowledge();
    $file = UploadedFile::fake()->createWithContent('politicas.txt', "Política de cambios\n\nSe aceptan cambios dentro de 30 días con factura.");

    $response = $this->actingAs($user)
        ->post("/api/knowledge-bases/{$kb->id}/documents", ['type' => 'file', 'file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('title', 'politicas')
        ->assertJsonPath('original_filename', 'politicas.txt');

    $document = Document::find($response->json('id'));
    Storage::disk('local')->assertExists($document->file_path);
    expect($document->status->value)->toBe('ready')
        ->and($document->content)->toContain('Se aceptan cambios');
});

test('los archivos se guardan en el disco privado, no en el público', function () {
    Storage::fake('public');
    [$user, $organization, $kb] = setupKnowledge();

    $this->actingAs($user)->post("/api/knowledge-bases/{$kb->id}/documents",
        ['type' => 'file', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'hola mundo')], ['Accept' => 'application/json']);

    $path = Document::first()->file_path;
    expect($path)->toStartWith("organizations/{$organization->id}/documents/");
    Storage::disk('public')->assertMissing($path);
});

test('se puede subir un PDF con texto', function () {
    [$user, , $kb] = setupKnowledge();
    $pdf = UploadedFile::fake()->createWithContent('catalogo.pdf', minimalPdf('Horario de atencion lunes a viernes de nueve a seis'));

    $id = $this->actingAs($user)
        ->post("/api/knowledge-bases/{$kb->id}/documents", ['type' => 'file', 'file' => $pdf], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('id');

    $document = Document::find($id);
    expect($document->status->value)->toBe('ready')
        ->and($document->content)->toContain('Horario de atencion');
});

test('un PDF sin texto (escaneado) queda en estado fallido con un mensaje claro', function () {
    [$user, , $kb] = setupKnowledge();
    $pdf = UploadedFile::fake()->createWithContent('escaneo.pdf', minimalPdf(''));

    $id = $this->actingAs($user)
        ->post("/api/knowledge-bases/{$kb->id}/documents", ['type' => 'file', 'file' => $pdf], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('id');

    $document = Document::find($id);
    expect($document->status->value)->toBe('failed')
        ->and($document->error)->toContain('No se encontró texto')
        ->and(chunkRowsOf($document))->toBe([]);
});

test('un PDF dañado también falla de forma controlada', function () {
    [$user, , $kb] = setupKnowledge();

    $id = $this->actingAs($user)
        ->post("/api/knowledge-bases/{$kb->id}/documents",
            ['type' => 'file', 'file' => UploadedFile::fake()->createWithContent('roto.pdf', 'esto no es un pdf')], ['Accept' => 'application/json'])
        ->json('id');

    expect(Document::find($id)->status->value)->toBe('failed');
});

test('rechaza tipos de archivo no soportados y archivos demasiado grandes', function () {
    [$user, , $kb] = setupKnowledge();
    $headers = ['Accept' => 'application/json'];

    $this->actingAs($user)->post("/api/knowledge-bases/{$kb->id}/documents",
        ['type' => 'file', 'file' => UploadedFile::fake()->create('virus.exe', 10)], $headers)
        ->assertJsonValidationErrors('file');

    $this->actingAs($user)->post("/api/knowledge-bases/{$kb->id}/documents",
        ['type' => 'file', 'file' => UploadedFile::fake()->create('hoja.xlsx', 10)], $headers)
        ->assertJsonValidationErrors('file');

    $this->actingAs($user)->post("/api/knowledge-bases/{$kb->id}/documents",
        ['type' => 'file', 'file' => UploadedFile::fake()->create('grande.pdf', 20000, 'application/pdf')], $headers)
        ->assertJsonValidationErrors('file');

    expect(Document::count())->toBe(0);
});

test('valida el tipo y los campos de cada tipo de documento', function () {
    [$user, , $kb] = setupKnowledge();
    $url = "/api/knowledge-bases/{$kb->id}/documents";

    $this->actingAs($user)->postJson($url, [])->assertJsonValidationErrors('type');
    $this->actingAs($user)->postJson($url, ['type' => 'video'])->assertJsonValidationErrors('type');
    $this->actingAs($user)->postJson($url, ['type' => 'text', 'title' => ''])->assertJsonValidationErrors(['title', 'content']);
    $this->actingAs($user)->postJson($url, ['type' => 'faq', 'title' => '¿Hola?'])->assertJsonValidationErrors('content');
    $this->actingAs($user)->postJson($url, ['type' => 'text', 'title' => 't', 'content' => str_repeat('a', 100001)])
        ->assertJsonValidationErrors('content');
});

test('la creación encola el procesamiento en el job', function () {
    Queue::fake();
    [$user, , $kb] = setupKnowledge();

    $id = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", [
        'type' => 'text', 'title' => 'x', 'content' => 'contenido',
    ])->json('id');

    Queue::assertPushed(ProcessDocument::class, fn ($job) => $job->documentId === $id);
    expect(Document::find($id)->status->value)->toBe('pending');
});

test('el listado trae los documentos sin el contenido y el detalle con contenido', function () {
    [$user, , $kb] = setupKnowledge();
    $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", ['type' => 'text', 'title' => 'Uno', 'content' => 'texto uno']);
    $id = Document::first()->id;

    $this->actingAs($user)->getJson("/api/knowledge-bases/{$kb->id}/documents")
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Uno')->assertJsonMissingPath('0.content');

    $this->actingAs($user)->getJson("/api/documents/{$id}")->assertOk()->assertJsonPath('content', 'texto uno');
});

test('actualizar el contenido reemplaza los fragmentos y vuelve a generar los vectores', function () {
    [$user, , $kb] = setupKnowledge();
    $id = $this->actingAs($user)->postJson("/api/knowledge-bases/{$kb->id}/documents", [
        'type' => 'text', 'title' => 'Precios', 'content' => 'El corte cuesta 25.000 pesos.',
    ])->json('id');
    $document = Document::find($id);
    $oldVector = DB::selectOne('select embedding::text as e from document_chunks where document_id = ?', [$id])->e;

    $this->actingAs($user)->putJson("/api/documents/{$id}", ['title' => 'Precios', 'content' => 'El corte ahora cuesta 30.000 pesos.'])
        ->assertOk()->assertJsonPath('content', 'El corte ahora cuesta 30.000 pesos.');

    $rows = chunkRowsOf($document->fresh());
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->content)->toContain('30.000')->not->toContain('25.000')
        ->and(DB::selectOne('select embedding::text as e from document_chunks where document_id = ?', [$id])->e)->not->toBe($oldVector)
        ->and($document->fresh()->status->value)->toBe('ready');
});

test('cambiar solo el título de un texto no vuelve a procesarlo, pero el de una FAQ sí', function () {
    Queue::fake();
    [$user, , $kb] = setupKnowledge();
    $text = Document::factory()->create(['knowledge_base_id' => $kb->id, 'title' => 'Viejo', 'content' => 'abc', 'status' => 'ready']);
    $faq = Document::factory()->create(['knowledge_base_id' => $kb->id, 'type' => 'faq', 'title' => '¿Viejo?', 'content' => 'abc', 'status' => 'ready']);

    $this->actingAs($user)->putJson("/api/documents/{$text->id}", ['title' => 'Nuevo', 'content' => 'abc'])->assertOk();
    Queue::assertNothingPushed();
    expect($text->fresh()->status->value)->toBe('ready');

    $this->actingAs($user)->putJson("/api/documents/{$faq->id}", ['title' => '¿Nuevo?', 'content' => 'abc'])->assertOk();
    Queue::assertPushed(ProcessDocument::class, 1);
    expect($faq->fresh()->status->value)->toBe('pending');
});

test('un archivo solo puede renombrarse, no editar su texto', function () {
    [$user, , $kb] = setupKnowledge();
    $file = Document::factory()->create(['knowledge_base_id' => $kb->id, 'type' => 'file', 'title' => 'a', 'content' => 'texto extraído']);

    $this->actingAs($user)->putJson("/api/documents/{$file->id}", ['title' => 'b', 'content' => 'otro'])->assertJsonValidationErrors('content');
    $this->actingAs($user)->putJson("/api/documents/{$file->id}", ['title' => 'b'])->assertOk()->assertJsonPath('title', 'b');
});

test('un documento fallido puede reprocesarse', function () {
    [$user, , $kb] = setupKnowledge();
    $document = Document::factory()->create([
        'knowledge_base_id' => $kb->id, 'content' => 'Contenido válido para indexar.', 'status' => 'failed', 'error' => 'falló',
    ]);

    $this->actingAs($user)->postJson("/api/documents/{$document->id}/reprocess")->assertStatus(202);

    $document->refresh();
    expect($document->status->value)->toBe('ready')->and($document->error)->toBeNull()->and($document->chunk_count)->toBe(1);
});

test('al borrar un documento se eliminan sus fragmentos y su archivo', function () {
    [$user, , $kb] = setupKnowledge();
    $id = $this->actingAs($user)->post("/api/knowledge-bases/{$kb->id}/documents",
        ['type' => 'file', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'contenido de prueba')], ['Accept' => 'application/json'])->json('id');
    $document = Document::find($id);
    $path = $document->file_path;
    expect(chunkRowsOf($document))->not->toBeEmpty();

    $this->actingAs($user)->deleteJson("/api/documents/{$id}")->assertNoContent();

    $this->assertDatabaseMissing('documents', ['id' => $id]);
    expect(DB::table('document_chunks')->where('document_id', $id)->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('no se accede a los documentos de otra empresa', function () {
    [$user] = userWithOrganization();
    [, , $foreignKb] = setupKnowledge();
    $foreign = Document::factory()->create(['knowledge_base_id' => $foreignKb->id]);

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$foreignKb->id}/documents", ['type' => 'text', 'title' => 'x', 'content' => 'y'])->assertNotFound();
    $this->actingAs($user)->getJson("/api/documents/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->putJson("/api/documents/{$foreign->id}", ['title' => 'x', 'content' => 'y'])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/documents/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->postJson("/api/documents/{$foreign->id}/reprocess")->assertNotFound();
    $this->assertDatabaseHas('documents', ['id' => $foreign->id]);
});

test('un miembro con rol agente solo puede consultar documentos', function () {
    [, $organization, $kb] = setupKnowledge();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    $document = Document::factory()->create(['knowledge_base_id' => $kb->id]);

    $this->actingAs($member)->getJson("/api/knowledge-bases/{$kb->id}/documents")->assertOk()->assertJsonCount(1);
    $this->actingAs($member)->getJson("/api/documents/{$document->id}")->assertOk();
    $this->actingAs($member)->postJson("/api/knowledge-bases/{$kb->id}/documents", ['type' => 'text', 'title' => 'x', 'content' => 'y'])->assertForbidden();
    $this->actingAs($member)->putJson("/api/documents/{$document->id}", ['title' => 'x', 'content' => 'y'])->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/documents/{$document->id}")->assertForbidden();
    $this->actingAs($member)->postJson("/api/documents/{$document->id}/reprocess")->assertForbidden();
});
