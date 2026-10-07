<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('un usuario verificado puede crear su empresa y queda como owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/organization', ['name' => 'Barbería XYZ'])
        ->assertCreated()
        ->assertJsonPath('name', 'Barbería XYZ')
        ->assertJsonPath('slug', 'barberia-xyz')
        ->assertJsonPath('business_hours.monday.enabled', true)
        ->assertJsonPath('business_hours.sunday.enabled', false);

    $user->refresh();
    expect($user->current_organization_id)->not->toBeNull()
        ->and($user->roleIn($user->currentOrganization))->toBe(OrganizationRole::Owner);
});

test('el slug se mantiene único cuando dos empresas se llaman igual', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->postJson('/api/organization', ['name' => 'Mi Empresa'])->assertCreated();
    $second = $this->actingAs($b)->postJson('/api/organization', ['name' => 'Mi Empresa'])->assertCreated();

    expect($second->json('slug'))->not->toBe('mi-empresa')->toStartWith('mi-empresa-');
});

test('una cuenta no puede crear una segunda empresa en el MVP', function () {
    [$user] = userWithOrganization();

    $this->actingAs($user)->postJson('/api/organization', ['name' => 'Otra'])->assertStatus(409);
});

test('sin correo verificado no se puede crear empresa', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/organization', ['name' => 'X'])->assertStatus(409);
    expect($user->organizations()->count())->toBe(0);
});

test('/api/user refleja empresa y rol', function () {
    [$user, $organization] = userWithOrganization(OrganizationRole::Admin);

    $this->actingAs($user)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('organization.id', $organization->id)
        ->assertJsonPath('role', 'admin')
        ->assertJsonPath('email_verified', true);
});

test('/api/user requiere autenticación', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

test('sin empresa, GET /api/organization responde 404', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/organization')->assertNotFound();
});

test('owner y admin pueden editar los datos de la empresa', function (OrganizationRole $role) {
    [$user] = userWithOrganization($role);

    $this->actingAs($user)->patchJson('/api/organization', [
        'name' => 'Nuevo nombre',
        'website' => 'https://ejemplo.com',
        'email' => 'hola@ejemplo.com',
        'phone' => '3001234567',
        'address' => 'Calle 1 # 2-3',
        'country' => 'co',
        'timezone' => 'America/Bogota',
    ])->assertOk()->assertJsonPath('name', 'Nuevo nombre')->assertJsonPath('country', 'CO');
})->with([OrganizationRole::Owner, OrganizationRole::Admin]);

test('acepta nombres de zona horaria heredados que envía el navegador', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/organization', ['name' => 'Mi tienda', 'timezone' => 'America/Buenos_Aires'])
        ->assertCreated()
        ->assertJsonPath('timezone', 'America/Buenos_Aires');
});

test('un agente no puede editar la empresa', function () {
    [$user] = userWithOrganization(OrganizationRole::Agent);

    $this->actingAs($user)->patchJson('/api/organization', ['name' => 'X', 'timezone' => 'UTC'])->assertForbidden();
    $this->actingAs($user)->putJson('/api/organization/business-hours', ['business_hours' => []])->assertForbidden();
});

test('valida zona horaria y sitio web', function () {
    [$user] = userWithOrganization();

    $this->actingAs($user)->patchJson('/api/organization', [
        'name' => 'X', 'timezone' => 'Marte/Olimpo', 'website' => 'javascript:alert(1)',
    ])->assertJsonValidationErrors(['timezone', 'website']);
});

test('se puede configurar el horario de atención', function () {
    [$user, $organization] = userWithOrganization();
    $hours = Organization::defaultBusinessHours();
    $hours['saturday'] = ['enabled' => true, 'open' => '08:00', 'close' => '13:00'];

    $this->actingAs($user)->putJson('/api/organization/business-hours', ['business_hours' => $hours])
        ->assertOk()
        ->assertJsonPath('business_hours.saturday.close', '13:00');

    expect($organization->fresh()->business_hours['saturday']['open'])->toBe('08:00');
});

test('el horario rechaza cierre anterior a apertura y días desconocidos', function () {
    [$user] = userWithOrganization();
    $hours = Organization::defaultBusinessHours();
    $hours['monday'] = ['enabled' => true, 'open' => '18:00', 'close' => '09:00'];

    $this->actingAs($user)->putJson('/api/organization/business-hours', ['business_hours' => $hours])
        ->assertJsonValidationErrors(['business_hours.monday.close']);

    $hours = Organization::defaultBusinessHours() + ['funday' => ['enabled' => true, 'open' => '09:00', 'close' => '10:00']];
    $this->actingAs($user)->putJson('/api/organization/business-hours', ['business_hours' => $hours])
        ->assertJsonValidationErrors(['business_hours']);
});

test('se puede subir y quitar el logo', function () {
    Storage::fake('public');
    [$user, $organization] = userWithOrganization();

    $response = $this->actingAs($user)
        ->post('/api/organization/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])
        ->assertOk();

    $path = $organization->fresh()->logo_path;
    expect($response->json('logo_url'))->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    $this->actingAs($user)->deleteJson('/api/organization/logo')->assertOk()->assertJsonPath('logo_url', null);
    Storage::disk('public')->assertMissing($path);
});

test('el logo rechaza archivos que no son imagen y SVG', function () {
    Storage::fake('public');
    [$user] = userWithOrganization();

    $this->actingAs($user)
        ->post('/api/organization/logo', ['logo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('logo');

    $this->actingAs($user)
        ->post('/api/organization/logo', ['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('logo');
});
