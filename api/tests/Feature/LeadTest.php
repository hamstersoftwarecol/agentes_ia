<?php

use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Str;

function leadIn(Organization $organization, array $attributes = []): Lead
{
    app(CurrentOrganization::class)->set($organization);
    $contact = Contact::create(['visitor_id' => (string) Str::uuid()]);

    return Lead::create($attributes + ['contact_id' => $contact->id, 'name' => 'Ana', 'phone' => '3001234567', 'interest' => 'Plan Pro']);
}

test('lista los leads de la empresa, el más reciente primero', function () {
    [$user, $organization] = userWithOrganization();
    [, $other] = userWithOrganization();
    $old = leadIn($organization, ['created_at' => now()->subDays(2)]);
    $new = leadIn($organization, ['created_at' => now()]);
    leadIn($other);

    $response = $this->actingAs($user)->getJson('/api/leads')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'phone', 'email', 'interest', 'status', 'notes', 'conversation_id', 'created_at']);
});

test('se puede filtrar por estado y paginar', function () {
    [$user, $organization] = userWithOrganization();
    foreach (range(1, 25) as $i) {
        leadIn($organization);
    }
    leadIn($organization, ['status' => 'converted']);

    $this->actingAs($user)->getJson('/api/leads?status=converted')->assertJsonCount(1, 'data');
    $page = $this->actingAs($user)->getJson('/api/leads?status=new')->assertOk();
    expect($page->json('data'))->toHaveCount(20)->and($page->json('meta.last_page'))->toBe(2);

    $this->actingAs($user)->getJson('/api/leads?status=inventado')->assertJsonValidationErrors('status');
});

test('recorre todo el embudo de estados', function () {
    [$user, $organization] = userWithOrganization();
    $lead = leadIn($organization);

    foreach (['contacted', 'qualified', 'converted', 'lost', 'new'] as $status) {
        $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['status' => $status])->assertOk()->assertJsonPath('status', $status);
    }
    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['status' => 'archivado'])->assertJsonValidationErrors('status');
});

test('se pueden agregar notas y corregir los datos del lead', function () {
    [$user, $organization] = userWithOrganization();
    $lead = leadIn($organization);

    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", [
        'notes' => 'Llamar el lunes a las 9', 'name' => 'Ana María', 'phone' => '+573109998877', 'email' => 'ana@example.com', 'interest' => 'Plan Business',
    ])->assertOk()->assertJsonPath('notes', 'Llamar el lunes a las 9')->assertJsonPath('phone', '+573109998877');

    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['notes' => null])->assertOk()->assertJsonPath('notes', null);
});

test('valida los datos al editar un lead', function () {
    [$user, $organization] = userWithOrganization();
    $lead = leadIn($organization);

    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['phone' => 'abc'])->assertJsonValidationErrors('phone');
    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['phone' => '123'])->assertJsonValidationErrors('phone');
    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['email' => 'mal'])->assertJsonValidationErrors('email');
    $this->actingAs($user)->patchJson("/api/leads/{$lead->id}", ['notes' => str_repeat('a', 2001)])->assertJsonValidationErrors('notes');
});

test('un miembro con rol agente puede trabajar los leads pero no borrarlos', function () {
    [$owner, $organization] = userWithOrganization();
    [$member] = userWithOrganization(OrganizationRole::Agent, $organization);
    $lead = leadIn($organization);

    $this->actingAs($member)->getJson('/api/leads')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($member)->patchJson("/api/leads/{$lead->id}", ['status' => 'contacted'])->assertOk();
    $this->actingAs($member)->deleteJson("/api/leads/{$lead->id}")->assertForbidden();

    $this->actingAs($owner)->deleteJson("/api/leads/{$lead->id}")->assertNoContent();
    $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
});

test('no se accede a los leads de otra empresa', function () {
    [$user] = userWithOrganization();
    [, $other] = userWithOrganization();
    $foreign = leadIn($other);

    $this->actingAs($user)->getJson("/api/leads/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->patchJson("/api/leads/{$foreign->id}", ['status' => 'lost'])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/leads/{$foreign->id}")->assertNotFound();
    expect($foreign->fresh()->status->value)->toBe('new');
});

test('los endpoints de leads exigen sesión y empresa', function () {
    $this->getJson('/api/leads')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/leads')->assertNotFound();
});

test('borrar la conversación de origen no borra el lead', function () {
    [$user, $organization] = userWithOrganization();
    $conversation = inboxConversation($organization, 'ai');
    $lead = leadIn($organization, ['conversation_id' => $conversation->id]);

    $conversation->delete();

    expect($lead->fresh()->conversation_id)->toBeNull();
});
