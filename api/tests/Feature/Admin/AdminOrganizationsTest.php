<?php

use App\Enums\OrganizationRole;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-15 12:00:00 UTC'));
afterEach(fn () => Carbon::setTestNow());

function namedOrg(string $name, string $plan = 'starter', array $subscription = []): array
{
    [$owner, $organization] = userWithOrganization();
    $owner->forceFill(['name' => "Dueño de {$name}", 'email' => strtolower(str_replace(' ', '', $name)).'@example.com'])->save();
    $organization->forceFill(['name' => $name])->save();
    setPlan($organization, $plan, ['source' => 'manual', 'current_period_end' => null] + $subscription);

    return [$owner, $organization];
}

// ---------- listado y detalle ----------

test('lista las empresas con dueño, plan, estado y consumo, paginadas', function () {
    namedOrg('Barbería Uno', 'business');
    foreach (range(1, 24) as $i) {
        namedOrg("Empresa $i");
    }
    usage(Organization::where('name', 'Barbería Uno')->first(), 120);

    $page = $this->actingAs(superadmin())->getJson('/api/admin/organizations')->assertOk();

    expect($page->json('data'))->toHaveCount(20)->and($page->json('meta.total'))->toBe(25)->and($page->json('meta.last_page'))->toBe(2);

    $row = collect($this->actingAs(superadmin())->getJson('/api/admin/organizations?page=2')->json('data'))->firstWhere('name', 'Barbería Uno');
    expect($row['owner'])->toBe(['name' => 'Dueño de Barbería Uno', 'email' => 'barberíauno@example.com'])
        ->and($row['plan'])->toBe(['slug' => 'business', 'name' => 'Business'])
        ->and($row['state'])->toBe('active')
        ->and($row['messages_used'])->toBe(120)->and($row['messages_limit'])->toBe(5000)
        ->and($row['members_count'])->toBe(1)->and($row['suspended'])->toBeFalse();
});

test('se busca por nombre sin distinguir mayúsculas', function () {
    namedOrg('Barbería Central');
    namedOrg('Pizzería Roma');

    $names = collect($this->actingAs(superadmin())->getJson('/api/admin/organizations?search=ROMA')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Pizzería Roma']);
});

test('el detalle trae suscripción, equipo, agentes, costo del mes y últimas acciones', function () {
    [, $organization] = namedOrg('Barbería Uno', 'business');
    [$member] = userWithOrganization(OrganizationRole::Admin, $organization);
    app(CurrentOrganization::class)->set($organization);
    Agent::factory()->create(['name' => 'Sofía', 'allowed_domains' => ['mitienda.com']]);
    usage($organization, 1, 'widget', now(), ['cost_micros' => 2_500_000]);
    usage($organization, 1, 'widget', now()->subMonth(), ['cost_micros' => 9_000_000]);
    AuditLog::create(['organization_id' => $organization->id, 'action' => 'subscription.granted', 'metadata' => ['plan' => 'business']]);

    $detail = $this->actingAs(superadmin())->getJson("/api/admin/organizations/{$organization->id}")->assertOk();

    expect($detail->json('subscription.source'))->toBe('manual')
        ->and($detail->json('members'))->toHaveCount(2)
        ->and(collect($detail->json('members'))->pluck('role')->sort()->values()->all())->toBe(['admin', 'owner'])
        ->and($detail->json('agents'))->toBe([['id' => Agent::withoutGlobalScopes()->first()->id, 'name' => 'Sofía', 'is_active' => true, 'domains' => ['mitienda.com']]])
        ->and($detail->json('ai_cost_micros_month'))->toBe(2_500_000)
        ->and($detail->json('audit.0.action'))->toBe('subscription.granted')
        ->and($detail->json('period_start'))->toStartWith('2026-10-01');
});

test('una empresa inexistente responde 404', function () {
    $this->actingAs(superadmin())->getJson('/api/admin/organizations/999999')->assertNotFound();
});

// ---------- plan por cortesía ----------

test('conceder un plan por cortesía activa la empresa al instante y deja auditoría', function () {
    [, $organization] = namedOrg('A', 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->subDay()]);
    $agent = widgetAgent($organization);
    expect(test()->getJson("/api/widget/{$agent->public_id}/config")->json('active'))->toBeFalse(); // prueba vencida
    $admin = superadmin();

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/plan", [
        'plan' => 'business', 'until' => now()->addMonths(3)->toDateString(), 'note' => 'Piloto con el cliente',
    ])->assertOk()->assertJsonPath('plan.slug', 'business')->assertJsonPath('state', 'active');

    app()->forgetScopedInstances();
    expect(test()->getJson("/api/widget/{$agent->public_id}/config")->json('active'))->toBeTrue();

    $subscription = $organization->fresh()->subscription;
    expect($subscription->source)->toBe('manual')->and($subscription->status->value)->toBe('active')
        ->and($subscription->current_period_end->toDateString())->toBe(now()->addMonths(3)->toDateString());

    $log = AuditLog::where('action', 'subscription.granted')->first();
    expect($log->user_id)->toBe($admin->id)->and($log->organization_id)->toBe($organization->id)
        ->and($log->metadata['plan'])->toBe('business')->and($log->metadata['note'])->toBe('Piloto con el cliente');
});

test('una cortesía sin fecha no vence', function () {
    [, $organization] = namedOrg('A');

    $this->actingAs(superadmin())->postJson("/api/admin/organizations/{$organization->id}/plan", ['plan' => 'pro'])->assertOk();

    expect($organization->fresh()->subscription->current_period_end)->toBeNull();
});

test('valida el plan y la fecha de la cortesía', function () {
    [, $organization] = namedOrg('A');
    $admin = superadmin();

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/plan", [])->assertJsonValidationErrors('plan');
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/plan", ['plan' => 'inventado'])->assertJsonValidationErrors('plan');
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/plan", ['plan' => 'pro', 'until' => '2020-01-01'])->assertJsonValidationErrors('until');
});

// ---------- extender la prueba ----------

test('extender una prueba vigente suma días a su fecha de fin', function () {
    [, $organization] = namedOrg('A', 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDays(2), 'current_period_end' => now()->addDays(2)]);
    $admin = superadmin();

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/extend-trial", ['days' => 7])->assertOk()->assertJsonPath('state', 'trialing');

    expect($organization->fresh()->subscription->trial_ends_at->toDateString())->toBe(now()->addDays(9)->toDateString())
        ->and(AuditLog::where('action', 'subscription.trial_extended')->first()->metadata)->toBe(['days' => 7]);
});

test('extender una prueba vencida cuenta desde hoy y la reactiva', function () {
    [, $organization] = namedOrg('A', 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->subDays(20), 'current_period_end' => now()->subDays(20)]);

    $this->actingAs(superadmin())->postJson("/api/admin/organizations/{$organization->id}/extend-trial", ['days' => 5])->assertOk()->assertJsonPath('state', 'trialing');

    expect($organization->fresh()->subscription->trial_ends_at->toDateString())->toBe(now()->addDays(5)->toDateString());
});

test('solo se extienden pruebas, y los días se validan', function () {
    [, $paying] = namedOrg('Paga', 'business', ['source' => 'stripe', 'status' => 'active']);
    [, $trial] = namedOrg('Prueba', 'starter', ['status' => 'trialing', 'source' => 'trial', 'trial_ends_at' => now()->addDay()]);
    $admin = superadmin();

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$paying->id}/extend-trial", ['days' => 5])->assertJsonValidationErrors('days');
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$trial->id}/extend-trial", ['days' => 0])->assertJsonValidationErrors('days');
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$trial->id}/extend-trial", ['days' => 91])->assertJsonValidationErrors('days');
});

// ---------- suspender ----------

test('suspender corta el servicio al instante y reactivar lo devuelve, siempre con auditoría', function () {
    [$owner, $organization] = namedOrg('A', 'pro');
    $agent = widgetAgent($organization);
    fakeAI();
    $admin = superadmin();

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/suspend", ['reason' => 'Uso abusivo del chat'])
        ->assertOk()->assertJsonPath('suspended', true)->assertJsonPath('state', 'suspended')->assertJsonPath('suspension_reason', 'Uso abusivo del chat');

    app()->forgetScopedInstances();
    sendWidget($agent, 'hola')->assertForbidden();
    $this->actingAs($owner->fresh())->postJson('/api/agents', ['name' => 'X', 'tone' => 'formal', 'language' => 'es'])->assertStatus(402);

    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/unsuspend")->assertOk()->assertJsonPath('suspended', false);
    app()->forgetScopedInstances();
    sendWidget($agent, 'hola')->assertCreated();

    expect(AuditLog::where('organization_id', $organization->id)->pluck('action')->sort()->values()->all())
        ->toBe(['organization.suspended', 'organization.unsuspended']);
    expect(AuditLog::where('action', 'organization.suspended')->first()->metadata)->toBe(['reason' => 'Uso abusivo del chat']);
});

test('suspender exige un motivo', function () {
    [, $organization] = namedOrg('A');

    $this->actingAs(superadmin())->postJson("/api/admin/organizations/{$organization->id}/suspend", [])->assertJsonValidationErrors('reason');
});

// ---------- usuarios y auditoría ----------

test('lista usuarios con sus empresas y permite buscar', function () {
    namedOrg('Barbería Uno');
    User::factory()->create(['name' => 'Sin Empresa', 'email' => 'solo@example.com', 'email_verified_at' => null]);

    $admin = superadmin(['name' => 'Equipo Hamster']);
    $all = collect($this->actingAs($admin)->getJson('/api/admin/users')->json('data'));
    $owner = $all->firstWhere('email', 'barberíauno@example.com');
    $solo = $all->firstWhere('email', 'solo@example.com');

    expect($owner['organizations'][0]['name'])->toBe('Barbería Uno')->and($owner['organizations'][0]['role'])->toBe('owner')->and($owner['verified'])->toBeTrue()
        ->and($solo['organizations'])->toBe([])->and($solo['verified'])->toBeFalse()
        ->and($all->firstWhere('name', 'Equipo Hamster')['is_superadmin'])->toBeTrue();

    expect(collect($this->actingAs($admin)->getJson('/api/admin/users?search=SOLO@')->json('data'))->pluck('email')->all())->toBe(['solo@example.com']);
});

test('el registro de auditoría muestra quién hizo qué, lo más reciente primero, y se filtra', function () {
    [, $a] = namedOrg('A');
    [, $b] = namedOrg('B');
    $admin = superadmin(['name' => 'Ada Admin']);
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$a->id}/suspend", ['reason' => 'x']);
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$b->id}/plan", ['plan' => 'pro']);
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$a->id}/unsuspend");

    $all = $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertOk();
    expect(collect($all->json('data'))->pluck('action')->all())->toBe(['organization.unsuspended', 'subscription.granted', 'organization.suspended'])
        ->and($all->json('data.0.actor'))->toBe('Ada Admin')->and($all->json('data.0.organization'))->toBe('A');

    expect(collect($this->actingAs($admin)->getJson('/api/admin/audit-logs?action=organization.')->json('data'))->pluck('action')->all())
        ->toBe(['organization.unsuspended', 'organization.suspended']);
    expect(collect($this->actingAs($admin)->getJson("/api/admin/audit-logs?organization_id={$b->id}")->json('data'))->pluck('action')->all())->toBe(['subscription.granted']);
});

test('las acciones de auditoría borran su rastro de la empresa sin romperse', function () {
    [, $organization] = namedOrg('A');
    $admin = superadmin();
    $this->actingAs($admin)->postJson("/api/admin/organizations/{$organization->id}/suspend", ['reason' => 'x']);

    $organization->delete(); // si se borra la empresa, el registro se conserva (sin empresa)

    $row = $this->actingAs($admin)->getJson('/api/admin/audit-logs')->json('data.0');
    expect($row['action'])->toBe('organization.suspended')->and($row['organization'])->toBeNull();
});
