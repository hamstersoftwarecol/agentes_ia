<?php

use App\Enums\OrganizationRole;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PlanEnded;
use App\Notifications\PlanEnding;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/** Empresa en prueba que termina dentro de $daysLeft días (negativo = ya terminó), con propietario, administrador y agente. */
function trialEndingIn(int $daysLeft, string $source = 'trial'): array
{
    [$owner, $organization] = userWithOrganization();
    [$admin] = userWithOrganization(OrganizationRole::Admin, $organization);
    [$agent] = userWithOrganization(OrganizationRole::Agent, $organization);

    Subscription::where('organization_id', $organization->id)->delete();
    $subscription = $source === 'trial'
        ? Subscription::factory()->trialing($daysLeft)->plan('starter')->create(['organization_id' => $organization->id])
        : Subscription::factory()->plan('business')->create(['organization_id' => $organization->id, 'current_period_end' => now()->addDays($daysLeft)]);

    return [$organization, $subscription, compact('owner', 'admin', 'agent')];
}

test('avisa a propietario y administradores cuando la prueba está por terminar, y solo una vez', function () {
    [$organization, , $team] = trialEndingIn(2);

    $this->artisan('billing:lifecycle')->expectsOutputToContain('fin próximo: 1')->assertSuccessful();
    $this->artisan('billing:lifecycle')->expectsOutputToContain('fin próximo: 0')->assertSuccessful();

    Notification::assertSentToTimes($team['owner'], PlanEnding::class, 1);
    Notification::assertSentToTimes($team['admin'], PlanEnding::class, 1);
    Notification::assertNotSentTo($team['agent'], PlanEnding::class);
    Notification::assertSentTo($team['owner'], PlanEnding::class, fn (PlanEnding $n) => $n->kind === 'trial' && $n->daysLeft === 2 && $n->organizationName === $organization->name);
});

test('no avisa si todavía falta mucho', function () {
    trialEndingIn(10);

    $this->artisan('billing:lifecycle')->assertSuccessful();

    Notification::assertNothingSent();
});

test('avisa una vez cuando la prueba terminó, sin repetir el de «termina pronto»', function () {
    [, , $team] = trialEndingIn(-1);

    $this->artisan('billing:lifecycle')->expectsOutputToContain('fin: 1')->assertSuccessful();
    $this->artisan('billing:lifecycle')->assertSuccessful();

    Notification::assertSentToTimes($team['owner'], PlanEnded::class, 1);
    Notification::assertNotSentTo($team['owner'], PlanEnding::class);
});

test('la prueba que ya terminó hace semanas no recibe un correo de golpe', function () {
    trialEndingIn(-30);

    $this->artisan('billing:lifecycle')->assertSuccessful();

    Notification::assertNothingSent();
});

test('el aviso de que terminó sale aunque nunca se hubiera dado el de que terminaba (creada con poco margen)', function () {
    [, $subscription, $team] = trialEndingIn(1);
    $this->artisan('billing:lifecycle')->assertSuccessful();   // «termina mañana»
    $subscription->forceFill(['trial_ends_at' => now()->subHour()])->save();

    $this->artisan('billing:lifecycle')->assertSuccessful();   // «terminó»

    Notification::assertSentToTimes($team['owner'], PlanEnding::class, 1);
    Notification::assertSentToTimes($team['owner'], PlanEnded::class, 1);
});

test('extender la prueba rehabilita los avisos', function () {
    [$organization, $subscription, $team] = trialEndingIn(1);
    $this->artisan('billing:lifecycle')->assertSuccessful();
    expect($subscription->fresh()->ending_notified_at)->not->toBeNull();

    app(SubscriptionService::class)->extendTrial($organization, 2, superadmin());
    expect($subscription->fresh())->ending_notified_at->toBeNull()->ended_notified_at->toBeNull();

    $this->artisan('billing:lifecycle')->assertSuccessful();
    Notification::assertSentToTimes($team['owner'], PlanEnding::class, 2);
});

test('un plan por cortesía con fecha de fin también avisa, con su propio texto; sin fecha no', function () {
    [, , $team] = trialEndingIn(2, 'manual');
    trialEndingIn(2, 'manual')[1]->forceFill(['current_period_end' => null])->save();

    $this->artisan('billing:lifecycle')->assertSuccessful();

    Notification::assertSentToTimes($team['owner'], PlanEnding::class, 1);
    Notification::assertSentTo($team['owner'], PlanEnding::class, fn (PlanEnding $n) => $n->kind === 'manual');
    Notification::assertCount(2); // propietario y administrador de la cortesía con fecha; la otra no avisa
});

test('las suscripciones de Stripe y las empresas suspendidas no reciben estos avisos', function () {
    [, $stripe] = trialEndingIn(2);
    $stripe->forceFill(['source' => 'stripe'])->save();
    [$suspended] = trialEndingIn(2);
    $suspended->forceFill(['suspended_at' => now()])->save();

    $this->artisan('billing:lifecycle')->assertSuccessful();

    Notification::assertNothingSent();
});

test('el correo de aviso dice cuándo termina y lleva a elegir un plan', function () {
    $mail = (new PlanEnding('trial', 'Barbería Demo', 3, '14 de octubre'))->toMail(new User(['name' => 'Ana']));

    expect($mail->subject)->toBe('La prueba gratuita de Barbería Demo termina en 3 días')
        ->and($mail->actionUrl)->toBe(config('app.frontend_url').'/billing')
        ->and(implode(' ', $mail->introLines))->toContain('14 de octubre');
    expect((new PlanEnding('trial', 'X', 1, '1 de enero'))->toMail(new User(['name' => 'Ana']))->subject)->toContain('mañana');
    expect((new PlanEnded('manual', 'X'))->toMail(new User(['name' => 'Ana']))->subject)->toBe('Terminó el plan de cortesía de X')
        ->and((new PlanEnding('manual', 'X', 3, '1 de enero'))->toMail(new User(['name' => 'Ana']))->subject)->toBe('El plan de cortesía de X termina en 3 días');
});

test('el comando está programado a diario', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'billing:lifecycle'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 13 * * *')->and($events->first()->withoutOverlapping)->toBeTrue();
});
