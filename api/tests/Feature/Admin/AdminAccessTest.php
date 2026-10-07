<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

const ADMIN_GET = [
    '/api/admin/overview', '/api/admin/usage', '/api/admin/organizations', '/api/admin/plans',
    '/api/admin/users', '/api/admin/audit-logs', '/api/admin/settings',
];

test('el panel de superadmin exige sesión', function (string $url) {
    $this->getJson($url)->assertUnauthorized();
})->with(ADMIN_GET);

test('un usuario normal (aunque sea propietario de una empresa) recibe 404, como si no existiera', function (string $url) {
    [$owner] = userWithOrganization();

    $this->actingAs($owner)->getJson($url)->assertNotFound();
})->with(ADMIN_GET);

test('un superadmin accede a todo', function (string $url) {
    $this->actingAs(superadmin())->getJson($url)->assertOk();
})->with(ADMIN_GET);

test('las acciones de escritura también están cerradas a usuarios normales', function () {
    [$owner, $organization] = userWithOrganization();
    $plan = Plan::first();

    $this->actingAs($owner)->postJson("/api/admin/organizations/{$organization->id}/suspend", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($owner)->postJson("/api/admin/organizations/{$organization->id}/plan", ['plan' => 'pro'])->assertNotFound();
    $this->actingAs($owner)->postJson("/api/admin/organizations/{$organization->id}/extend-trial", ['days' => 5])->assertNotFound();
    $this->actingAs($owner)->patchJson("/api/admin/plans/{$plan->id}", ['price_cents' => 1])->assertNotFound();

    expect($organization->fresh()->isSuspended())->toBeFalse()->and($plan->fresh()->price_cents)->not->toBe(1);
});

test('el correo sin verificar tampoco entra', function () {
    $user = superadmin();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->getJson('/api/admin/overview')->assertStatus(409);
});

test('nadie puede volverse superadmin por la API: ni registrándose ni editando su perfil', function () {
    $this->post('/api/register', [
        'name' => 'Intruso', 'email' => 'intruso@example.com', 'password' => 'Clave-segura-123', 'password_confirmation' => 'Clave-segura-123', 'is_superadmin' => true,
    ], ['Accept' => 'application/json'])->assertNoContent();
    expect(User::where('email', 'intruso@example.com')->first()->is_superadmin)->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/user/profile', ['name' => 'X', 'email' => $user->email, 'is_superadmin' => true])->assertOk();
    expect($user->fresh()->is_superadmin)->toBeFalse();
});

test('/api/user informa si la cuenta es superadmin (el panel lo usa para mostrar el acceso)', function () {
    expect($this->actingAs(superadmin())->getJson('/api/user')->json('is_superadmin'))->toBeTrue()
        ->and($this->actingAs(User::factory()->create())->getJson('/api/user')->json('is_superadmin'))->toBeFalse();
});

test('admin:promote da y quita el acceso', function () {
    $user = User::factory()->create(['email' => 'equipo@hamster.test']);

    $this->artisan('admin:promote equipo@hamster.test')->expectsOutputToContain('ahora es superadmin')->assertSuccessful();
    expect($user->fresh()->is_superadmin)->toBeTrue();

    $this->artisan('admin:promote EQUIPO@hamster.test --revoke')->expectsOutputToContain('ya no es superadmin')->assertSuccessful();
    expect($user->fresh()->is_superadmin)->toBeFalse();

    $this->artisan('admin:promote nadie@example.com')->expectsOutputToContain('No existe')->assertFailed();
});

test('el seeder de desarrollo deja al usuario demo como superadmin con un plan Pro de cortesía', function () {
    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', 'demo@hamster.test')->first();
    $organization = Organization::where('slug', 'barberia-demo')->first();

    expect($demo->is_superadmin)->toBeTrue()
        ->and($organization->subscription->plan->slug)->toBe('pro')
        ->and($organization->subscription->source)->toBe('manual');
});
