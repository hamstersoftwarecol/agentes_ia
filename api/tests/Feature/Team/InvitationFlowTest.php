<?php

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/** Una empresa que invita a $email; devuelve [organización, token en claro, propietario]. */
function inviteTo(string $email, string $role = 'agent', string $plan = 'business'): array
{
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, $plan);

    test()->actingAs($owner)->postJson('/api/team/invitations', ['email' => $email, 'role' => $role])->assertCreated();
    resetRequestState();
    auth()->forgetGuards();

    return [$organization, basename(lastInvitationUrl()), $owner];
}

test('la vista previa muestra la empresa, el rol y si el correo ya tiene cuenta', function () {
    [$organization, $token] = inviteTo('nuevo@example.com', 'admin');

    $this->getJson("/api/invitations/$token")->assertOk()->assertJson([
        'email' => 'nuevo@example.com', 'role' => 'admin', 'account_exists' => false,
        'organization' => ['name' => $organization->name],
    ]);

    User::factory()->create(['email' => 'nuevo@example.com']);
    $this->getJson("/api/invitations/$token")->assertJsonPath('account_exists', true);
});

test('un token inventado no existe y uno vencido responde 410', function () {
    [, $token] = inviteTo('nuevo@example.com');

    $this->getJson('/api/invitations/'.str_repeat('x', 48))->assertNotFound();
    Invitation::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
    $this->getJson("/api/invitations/$token")->assertStatus(410);
});

test('quien no tiene cuenta se registra desde la invitación: queda dentro de la empresa, verificado y con la sesión iniciada', function () {
    [$organization, $token] = inviteTo('nuevo@example.com', 'agent');

    $this->postJson("/api/invitations/$token/register", ['name' => 'Nuevo Miembro', 'password' => 'una-clave-larga-123', 'password_confirmation' => 'una-clave-larga-123'])
        ->assertCreated()->assertJsonPath('email', 'nuevo@example.com')->assertJsonPath('role', 'agent')->assertJsonPath('organization.id', $organization->id);

    $user = User::where('email', 'nuevo@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($organization->roleOf($user))->toBe(OrganizationRole::Agent)
        ->and($user->current_organization_id)->toBe($organization->id)
        ->and(Invitation::withoutGlobalScopes()->count())->toBe(0);
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'action' => 'team.invitation_accepted']);

    // Un enlace se usa una sola vez.
    $this->postJson("/api/invitations/$token/register", ['name' => 'Otro', 'password' => 'una-clave-larga-123', 'password_confirmation' => 'una-clave-larga-123'])->assertNotFound();
});

test('registrarse desde la invitación valida nombre y contraseña, y no crea nada si falla', function () {
    [, $token] = inviteTo('nuevo@example.com');

    $this->postJson("/api/invitations/$token/register", ['name' => '', 'password' => 'corta', 'password_confirmation' => 'distinta'])
        ->assertJsonValidationErrors(['name', 'password']);

    expect(User::where('email', 'nuevo@example.com')->exists())->toBeFalse()->and(Invitation::withoutGlobalScopes()->count())->toBe(1);
});

test('si el correo ya tiene cuenta no se puede crear otra: debe iniciar sesión', function () {
    [, $token] = inviteTo('existente@example.com');
    User::factory()->create(['email' => 'existente@example.com']);

    $this->postJson("/api/invitations/$token/register", ['name' => 'X', 'password' => 'una-clave-larga-123', 'password_confirmation' => 'una-clave-larga-123'])
        ->assertStatus(409);
});

test('la invitación vencida no permite registrarse ni aceptar', function () {
    [, $token] = inviteTo('nuevo@example.com');
    $user = User::factory()->create(['email' => 'nuevo@example.com']);
    Invitation::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);

    $this->postJson("/api/invitations/$token/register", ['name' => 'X', 'password' => 'una-clave-larga-123', 'password_confirmation' => 'una-clave-larga-123'])->assertStatus(410);
    $this->actingAs($user)->postJson("/api/invitations/$token/accept")->assertStatus(410);
});

test('quien ya tiene cuenta (sin empresa) acepta con la sesión iniciada', function () {
    [$organization, $token] = inviteTo('ana@example.com', 'admin');
    $ana = User::factory()->create(['email' => 'ana@example.com']);

    $this->actingAs($ana)->postJson("/api/invitations/$token/accept")
        ->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('organization.id', $organization->id);

    expect($organization->roleOf($ana))->toBe(OrganizationRole::Admin)->and($ana->fresh()->current_organization_id)->toBe($organization->id)
        ->and(Invitation::withoutGlobalScopes()->count())->toBe(0);
});

test('una invitación solo la acepta el correo al que se envió', function () {
    [$organization, $token] = inviteTo('ana@example.com');
    $intruso = User::factory()->create(['email' => 'intruso@example.com']);

    $this->actingAs($intruso)->postJson("/api/invitations/$token/accept")->assertForbidden();

    expect($organization->users()->whereKey($intruso->id)->exists())->toBeFalse()->and(Invitation::withoutGlobalScopes()->count())->toBe(1);
});

test('aceptar exige sesión y correo verificado', function () {
    [, $token] = inviteTo('ana@example.com');
    $this->postJson("/api/invitations/$token/accept")->assertUnauthorized();

    // El middleware `verified` de Laravel responde 409 a las peticiones JSON.
    $sinVerificar = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    $this->actingAs($sinVerificar)->postJson("/api/invitations/$token/accept")->assertStatus(409);
    expect(Invitation::withoutGlobalScopes()->count())->toBe(1)->and($sinVerificar->organizations()->exists())->toBeFalse();
});

test('una cuenta que ya pertenece a otra empresa no puede unirse con la misma cuenta', function () {
    [, $token] = inviteTo('ana@example.com');
    $ana = User::factory()->create(['email' => 'ana@example.com']);
    userWithOrganization(OrganizationRole::Owner)[1]->users()->attach($ana, ['role' => 'agent']);

    $this->actingAs($ana)->postJson("/api/invitations/$token/accept")->assertStatus(409);
    expect(Invitation::withoutGlobalScopes()->count())->toBe(1);
});

test('si el plan bajó y ya no caben más personas, aceptar responde 402 y no crea la cuenta', function () {
    [$organization, $token] = inviteTo('nuevo@example.com', 'agent', 'business');
    // El propietario baja a Starter (1 usuario) con la invitación todavía pendiente.
    setPlan($organization, 'starter');

    $this->postJson("/api/invitations/$token/register", ['name' => 'X', 'password' => 'una-clave-larga-123', 'password_confirmation' => 'una-clave-larga-123'])
        ->assertStatus(402)->assertJson(['reason' => 'users']);

    expect(User::where('email', 'nuevo@example.com')->exists())->toBeFalse();
});

test('dos invitaciones simultáneas no superan el tope de usuarios al aceptarse', function () {
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, 'business');
    foreach (['a@example.com', 'b@example.com'] as $email) {
        $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => $email, 'role' => 'agent'])->assertCreated();
        $tokens[$email] = basename(lastInvitationUrl());
    }
    // El plan queda en 2 usuarios: el propietario + 1.
    $organization->subscription->plan->update(['max_users' => 2]);
    resetRequestState();
    auth()->forgetGuards();

    $a = User::factory()->create(['email' => 'a@example.com']);
    $b = User::factory()->create(['email' => 'b@example.com']);
    $this->actingAs($a)->postJson("/api/invitations/{$tokens['a@example.com']}/accept")->assertOk();
    resetRequestState();
    $this->actingAs($b)->postJson("/api/invitations/{$tokens['b@example.com']}/accept")->assertStatus(402);

    expect($organization->users()->count())->toBe(2);
});

test('mirar o aceptar invitaciones tiene tope por IP', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/invitations/'.str_repeat('z', 48))->assertNotFound();
    }
    $this->getJson('/api/invitations/'.str_repeat('z', 48))->assertStatus(429);
});
