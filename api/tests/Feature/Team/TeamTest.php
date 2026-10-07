<?php

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TeamInvitation;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/** Empresa en un plan dado con su propietario, y cualquier miembro extra que se pida. */
function teamOf(string $plan = 'business', array $extra = []): array
{
    [$owner, $organization] = userWithOrganization();
    setPlan($organization, $plan);

    $members = [];
    foreach ($extra as $key => $role) {
        [$members[$key]] = userWithOrganization($role, $organization);
    }

    return [$owner, $organization, $members];
}

test('lista los miembros con su rol y qué puede editar quien consulta', function () {
    [$owner, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin, 'agent' => OrganizationRole::Agent]);

    $response = $this->actingAs($owner)->getJson('/api/team')->assertOk();

    $byId = collect($response->json('members'))->keyBy('id');
    expect($byId)->toHaveCount(3)
        ->and($byId[$owner->id])->toMatchArray(['role' => 'owner', 'is_you' => true, 'can_edit' => false])
        ->and($byId[$m['admin']->id])->toMatchArray(['role' => 'admin', 'is_you' => false, 'can_edit' => true])
        ->and($byId[$m['agent']->id]['can_edit'])->toBeTrue()
        ->and($response->json('can_manage'))->toBeTrue()
        ->and($response->json('seats'))->toMatchArray(['members' => 3, 'pending' => 0, 'used' => 3, 'limit' => 10]);
});

test('un administrador solo puede editar agentes y un agente no edita a nadie', function () {
    [, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin, 'agent' => OrganizationRole::Agent]);

    $asAdmin = collect($this->actingAs($m['admin'])->getJson('/api/team')->json('members'))->keyBy('id');
    expect($asAdmin->where('can_edit', true)->keys()->all())->toBe([$m['agent']->id]);

    resetRequestState();
    $asAgent = $this->actingAs($m['agent'])->getJson('/api/team')->assertOk();
    expect(collect($asAgent->json('members'))->where('can_edit', true))->toBeEmpty()
        ->and($asAgent->json('can_manage'))->toBeFalse();
});

test('quien no administra no ve las invitaciones pendientes (son correos de gente que aún no es parte)', function () {
    [$owner, , $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'nueva@example.com', 'role' => 'agent'])->assertCreated();

    resetRequestState();
    $this->actingAs($m['agent'])->getJson('/api/team')->assertOk()->assertJsonPath('invitations', []);
});

test('el propietario invita: el enlace solo viaja por correo y el token no se guarda en claro', function () {
    [$owner, $organization] = teamOf('business');

    $response = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'Nueva@Example.com', 'role' => 'agent'])->assertCreated();

    $response->assertJsonPath('invitation.email', 'nueva@example.com')->assertJsonPath('invitation.role', 'agent')->assertJsonPath('emailed', true);
    $token = basename(lastInvitationUrl());
    expect(lastInvitationUrl())->toStartWith(config('app.frontend_url').'/invitations/');
    // El enlace es la prueba de que quien se une controla ese correo: a quien invita no se le devuelve.
    $response->assertJsonMissingPath('link');
    expect($response->getContent())->not->toContain($token);

    $stored = Invitation::withoutGlobalScopes()->where('organization_id', $organization->id)->sole();
    expect($stored->token_hash)->toBe(hash('sha256', $token))->not->toBe($token)
        ->and($stored->expires_at->isFuture())->toBeTrue()
        ->and($stored->invited_by)->toBe($owner->id);
    expect(json_encode($this->actingAs($owner)->getJson('/api/team')->json()))->not->toContain($token)->not->toContain($stored->token_hash);

    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $n, array $channels, $notifiable) use ($token) {
        return $notifiable->routes['mail'] === 'nueva@example.com' && str_ends_with($n->url, $token) && $n->roleLabel === 'agente';
    });
    $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'user_id' => $owner->id, 'action' => 'team.invited']);
});

test('si el correo no se puede enviar la invitación existe, se avisa y NO se devuelve el enlace (se reenvía)', function () {
    [$owner] = teamOf('business');
    Notification::shouldReceive('route')->andThrow(new RuntimeException('SMTP caído'));

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'nueva@example.com', 'role' => 'agent'])
        ->assertCreated()->assertJsonPath('emailed', false)->assertJsonMissingPath('link');
    expect(Invitation::withoutGlobalScopes()->count())->toBe(1);
});

test('el tope de usuarios del plan cuenta miembros e invitaciones vigentes', function () {
    [$owner] = teamOf('business'); // 3 usuarios: el propietario + 2

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'b@example.com', 'role' => 'agent'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'c@example.com', 'role' => 'agent'])
        ->assertStatus(402)->assertJson(['code' => 'plan_limit', 'reason' => 'users']);

    expect(Invitation::withoutGlobalScopes()->count())->toBe(2);
});

test('Starter incluye un solo usuario: no se puede invitar a nadie', function () {
    [$owner] = teamOf('starter');

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])
        ->assertStatus(402)->assertJson(['reason' => 'users']);
});

test('una invitación vencida no ocupa plaza', function () {
    [$owner, $organization] = teamOf('business');
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'b@example.com', 'role' => 'agent'])->assertCreated();
    Invitation::withoutGlobalScopes()->where('email', 'a@example.com')->update(['expires_at' => now()->subDay()]);

    $this->actingAs($owner)->getJson('/api/team')->assertJsonPath('seats.pending', 1)->assertJsonPath('seats.used', 2);
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'c@example.com', 'role' => 'agent'])->assertCreated();
});

test('invitar de nuevo al mismo correo renueva la invitación: no duplica ni gasta otra plaza', function () {
    [$owner] = teamOf('starter', []);
    setPlan(Organization::first(), 'business');
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'b@example.com', 'role' => 'agent'])->assertCreated();

    $again = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'admin'])->assertCreated();

    expect(Invitation::withoutGlobalScopes()->count())->toBe(2)
        ->and(invitationUrls())->toHaveCount(3)->and(invitationUrls()[2])->not->toBe(invitationUrls()[0]) // a, b y a otra vez: enlace nuevo
        ->and(Invitation::withoutGlobalScopes()->where('email', 'a@example.com')->value('role'))->toBe('admin');
});

test('reenviar genera un enlace nuevo y el anterior deja de servir', function () {
    [$owner] = teamOf('business');
    $id = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->json('invitation.id');

    $first = basename(lastInvitationUrl());

    $this->actingAs($owner)->postJson("/api/team/invitations/{$id}/resend")->assertOk()->assertJsonMissingPath('link');
    $second = basename(lastInvitationUrl());

    expect($second)->not->toBe($first);
    $this->getJson("/api/invitations/$first")->assertNotFound();
    $this->getJson("/api/invitations/$second")->assertOk();
});

test('no se invita a quien ya es parte del equipo', function () {
    [$owner, , $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => $m['agent']->email, 'role' => 'agent'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

test('valida el correo y el rol', function () {
    [$owner] = teamOf('pro');

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'no-es-correo', 'role' => 'agent'])->assertJsonValidationErrors('email');
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'owner'])->assertJsonValidationErrors('role');
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com'])->assertJsonValidationErrors('role');
});

test('quien no administra recibe 403 antes que cualquier error de validación', function () {
    [, , $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);

    $this->actingAs($m['agent'])->postJson('/api/team/invitations', [])->assertForbidden();
});

test('un administrador invita agentes pero no administradores', function () {
    [, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin]);

    $this->actingAs($m['admin'])->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($m['admin'])->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();
});

test('con el plan vencido no se puede invitar', function () {
    [$owner, $organization] = teamOf('pro');
    setPlan($organization, 'pro', ['status' => 'canceled']);

    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])
        ->assertStatus(402)->assertJson(['reason' => 'inactive']);
});

test('se puede revocar una invitación y un administrador no revoca la de un administrador', function () {
    [$owner, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin]);
    $forAdmin = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'admin'])->json('invitation.id');
    $forAgent = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'b@example.com', 'role' => 'agent'])->json('invitation.id');

    $this->actingAs($m['admin'])->deleteJson("/api/team/invitations/$forAdmin")->assertForbidden();
    $this->actingAs($m['admin'])->deleteJson("/api/team/invitations/$forAgent")->assertNoContent();
    $this->actingAs($owner)->deleteJson("/api/team/invitations/$forAdmin")->assertNoContent();

    expect(Invitation::withoutGlobalScopes()->count())->toBe(0);
});

test('el propietario cambia el rol de un miembro', function () {
    [$owner, $organization, $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);

    $this->actingAs($owner)->patchJson("/api/team/members/{$m['agent']->id}", ['role' => 'admin'])->assertOk();

    expect($organization->roleOf($m['agent']))->toBe(OrganizationRole::Admin);
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.role_changed', 'organization_id' => $organization->id]);
});

test('nadie cambia su propio rol, ni se toca al propietario, ni un administrador asciende a otro', function () {
    [$owner, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin, 'agent' => OrganizationRole::Agent]);

    $this->actingAs($owner)->patchJson("/api/team/members/{$owner->id}", ['role' => 'agent'])->assertForbidden();
    $this->actingAs($m['admin'])->patchJson("/api/team/members/{$owner->id}", ['role' => 'agent'])->assertForbidden();
    $this->actingAs($m['admin'])->patchJson("/api/team/members/{$m['agent']->id}", ['role' => 'admin'])->assertForbidden();
    $this->actingAs($m['agent'])->patchJson("/api/team/members/{$m['admin']->id}", ['role' => 'agent'])->assertForbidden();
    $this->actingAs($owner)->patchJson("/api/team/members/{$m['admin']->id}", ['role' => 'owner'])->assertJsonValidationErrors('role');
});

test('el propietario quita a un miembro: pierde el acceso y sus conversaciones vuelven a esperar', function () {
    [$owner, $organization, $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);
    $conversation = inboxConversation($organization, 'human', ['assigned_user_id' => $m['agent']->id]);
    $closed = inboxConversation($organization, 'closed', ['assigned_user_id' => $m['agent']->id]);

    $this->actingAs($owner)->deleteJson("/api/team/members/{$m['agent']->id}")->assertNoContent();

    expect($organization->users()->whereKey($m['agent']->id)->exists())->toBeFalse()
        ->and($m['agent']->fresh()->current_organization_id)->toBeNull()
        ->and($conversation->fresh()->status->value)->toBe('waiting')
        ->and($conversation->fresh()->assigned_user_id)->toBeNull()
        ->and($closed->fresh()->status->value)->toBe('closed');
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.member_removed', 'organization_id' => $organization->id]);

    resetRequestState();
    $this->actingAs($m['agent']->fresh())->getJson('/api/agents')->assertNotFound();
});

test('nadie se quita a sí mismo ni al propietario, y un administrador no quita a otro administrador', function () {
    [$owner, , $m] = teamOf('pro', ['admin' => OrganizationRole::Admin, 'admin2' => OrganizationRole::Admin, 'agent' => OrganizationRole::Agent]);

    $this->actingAs($owner)->deleteJson("/api/team/members/{$owner->id}")->assertForbidden();
    $this->actingAs($m['admin'])->deleteJson("/api/team/members/{$m['admin']->id}")->assertForbidden();
    $this->actingAs($m['admin'])->deleteJson("/api/team/members/{$owner->id}")->assertForbidden();
    $this->actingAs($m['admin'])->deleteJson("/api/team/members/{$m['admin2']->id}")->assertForbidden();
    $this->actingAs($m['agent'])->deleteJson("/api/team/members/{$m['admin']->id}")->assertForbidden();
    $this->actingAs($m['admin'])->deleteJson("/api/team/members/{$m['agent']->id}")->assertNoContent();
});

test('otra empresa no ve ni toca a este equipo ni sus invitaciones', function () {
    [$owner, , $m] = teamOf('pro', ['agent' => OrganizationRole::Agent]);
    $invitation = $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'a@example.com', 'role' => 'agent'])->json('invitation.id');

    resetRequestState();
    [$stranger] = teamOf('pro');

    $list = $this->actingAs($stranger)->getJson('/api/team')->assertOk();
    expect($list->json('members'))->toHaveCount(1)->and($list->json('invitations'))->toBe([]);
    $this->actingAs($stranger)->patchJson("/api/team/members/{$m['agent']->id}", ['role' => 'admin'])->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/api/team/members/{$m['agent']->id}")->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/api/team/invitations/$invitation")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/team/invitations/$invitation/resend")->assertNotFound();
    expect(User::find($m['agent']->id)->organizations()->count())->toBe(1);
});

test('hay un tope de invitaciones por hora y por empresa para no usar la app como emisor de correo', function () {
    config(['team.invitations_per_hour' => 3]);
    [$owner] = teamOf('pro');
    [$other] = teamOf('pro');

    // Cada petición de producción arranca sin estado: el límite no puede depender de la empresa ya fijada.
    foreach (range(1, 3) as $i) {
        resetRequestState();
        $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => "p$i@example.com", 'role' => 'agent'])->assertCreated();
    }
    resetRequestState();
    $this->actingAs($owner)->postJson('/api/team/invitations', ['email' => 'p4@example.com', 'role' => 'agent'])->assertStatus(429);

    resetRequestState();
    $this->actingAs($other)->postJson('/api/team/invitations', ['email' => 'p1@example.com', 'role' => 'agent'])->assertCreated();
});

test('las rutas del equipo exigen sesión', function () {
    $this->getJson('/api/team')->assertUnauthorized();
    $this->postJson('/api/team/invitations', [])->assertUnauthorized();
});
