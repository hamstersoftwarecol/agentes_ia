<?php

use App\Enums\OrganizationRole;
use App\Models\Agent;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Storage;

function createKnowledgeBaseIn(Organization $organization, array $attributes = []): KnowledgeBase
{
    app(CurrentOrganization::class)->set($organization);

    return KnowledgeBase::factory()->create($attributes);
}

test('se puede crear una base de conocimiento', function () {
    [$user, $organization] = userWithOrganization();

    $this->actingAs($user)->postJson('/api/knowledge-bases', ['name' => 'Servicios y precios', 'description' => 'Todo sobre lo que vendemos'])
        ->assertCreated()
        ->assertJsonPath('name', 'Servicios y precios')
        ->assertJsonPath('documents_count', 0);

    $this->assertDatabaseHas('knowledge_bases', ['organization_id' => $organization->id, 'name' => 'Servicios y precios']);
});

test('el nombre es obligatorio', function () {
    [$user] = userWithOrganization();

    $this->actingAs($user)->postJson('/api/knowledge-bases', ['name' => ''])->assertJsonValidationErrors('name');
});

test('la lista cuenta documentos totales y listos, solo de la empresa actual', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    $kb = createKnowledgeBaseIn($organization, ['name' => 'Mía']);
    Document::factory()->create(['knowledge_base_id' => $kb->id, 'status' => 'ready']);
    Document::factory()->create(['knowledge_base_id' => $kb->id, 'status' => 'failed']);
    createKnowledgeBaseIn($other, ['name' => 'Ajena']);

    $this->actingAs($user)->getJson('/api/knowledge-bases')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Mía')
        ->assertJsonPath('0.documents_count', 2)
        ->assertJsonPath('0.ready_documents_count', 1);
});

test('no se puede acceder a la base de conocimiento de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    $foreign = createKnowledgeBaseIn($other);

    $this->actingAs($user)->getJson("/api/knowledge-bases/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->patchJson("/api/knowledge-bases/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/knowledge-bases/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->getJson("/api/knowledge-bases/{$foreign->id}/documents")->assertNotFound();
});

test('se puede renombrar', function () {
    [$user, $organization] = userWithOrganization();
    $kb = createKnowledgeBaseIn($organization);

    $this->actingAs($user)->putJson("/api/knowledge-bases/{$kb->id}", ['name' => 'Nuevo nombre'])
        ->assertOk()->assertJsonPath('name', 'Nuevo nombre');
});

test('al borrarla se eliminan sus documentos, fragmentos y archivos; los agentes quedan sin conocimiento', function () {
    Storage::fake('local');
    [$user, $organization] = userWithOrganization();
    $kb = createKnowledgeBaseIn($organization);
    Storage::disk('local')->put('organizations/1/documents/a.txt', 'contenido');
    $document = Document::factory()->create(['knowledge_base_id' => $kb->id, 'type' => 'file', 'file_path' => 'organizations/1/documents/a.txt']);
    $agent = Agent::factory()->create(['knowledge_base_id' => $kb->id]);

    $this->actingAs($user)->deleteJson("/api/knowledge-bases/{$kb->id}")->assertNoContent();

    $this->assertDatabaseMissing('knowledge_bases', ['id' => $kb->id]);
    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    Storage::disk('local')->assertMissing('organizations/1/documents/a.txt');
    expect($agent->fresh()->knowledge_base_id)->toBeNull();
});

test('solo propietario y administradores pueden modificar; los demás miembros solo ver', function () {
    [, $organization] = userWithOrganization();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    $kb = createKnowledgeBaseIn($organization);

    $this->actingAs($member)->getJson('/api/knowledge-bases')->assertOk()->assertJsonCount(1);
    $this->actingAs($member)->postJson('/api/knowledge-bases', ['name' => 'x'])->assertForbidden();
    $this->actingAs($member)->putJson("/api/knowledge-bases/{$kb->id}", ['name' => 'x'])->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/knowledge-bases/{$kb->id}")->assertForbidden();

    $this->actingAs($admin)->postJson('/api/knowledge-bases', ['name' => 'Del admin'])->assertCreated();
});
