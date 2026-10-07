<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(private readonly BillingGate $gate, private readonly AuditLogger $audit) {}

    /** Prueba gratuita al crear la empresa. Si el plan de prueba no existe no se rompe el alta: queda sin servicio. */
    public function startTrial(Organization $organization): ?Subscription
    {
        $plan = Plan::where('slug', config('billing.trial_plan'))->first();
        if (! $plan || $organization->subscription()->exists()) {
            return null;
        }

        $days = (int) config('billing.trial_days');

        return $organization->subscription()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'source' => 'trial',
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
        ]);
    }

    /** Plan por cortesía (soporte, acuerdos comerciales). Sin Stripe de por medio; `$until` null = sin vencimiento. */
    public function grant(Organization $organization, Plan $plan, ?CarbonInterface $until, User $admin, ?string $note = null): Subscription
    {
        $subscription = Subscription::updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'source' => 'manual',
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $until,
            'cancel_at_period_end' => false,
            'ending_notified_at' => null,
            'ended_notified_at' => null,
        ]);

        $this->gate->forget($organization);
        $this->audit->log('subscription.granted', $organization, $admin, $subscription, [
            'plan' => $plan->slug, 'until' => $until?->toIso8601String(), 'note' => $note,
        ]);

        return $subscription;
    }

    public function extendTrial(Organization $organization, int $days, User $admin): Subscription
    {
        $subscription = $organization->subscription;

        if (! $subscription || $subscription->status !== SubscriptionStatus::Trialing) {
            throw ValidationException::withMessages(['days' => 'Solo se puede extender la prueba de una empresa que está en período de prueba.']);
        }

        // Una prueba vencida se extiende desde hoy, no desde su fecha pasada.
        $base = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now();
        $subscription->forceFill([
            'trial_ends_at' => $base->copy()->addDays($days),
            'current_period_end' => $base->copy()->addDays($days),
            // La prueba tiene fecha nueva: los avisos de fin vuelven a aplicar.
            'ending_notified_at' => null,
            'ended_notified_at' => null,
        ])->save();

        $this->gate->forget($organization);
        $this->audit->log('subscription.trial_extended', $organization, $admin, $subscription, ['days' => $days]);

        return $subscription;
    }

    public function suspend(Organization $organization, string $reason, User $admin): void
    {
        $organization->forceFill(['suspended_at' => now(), 'suspension_reason' => $reason])->save();
        $this->gate->forget($organization);
        $this->audit->log('organization.suspended', $organization, $admin, $organization, ['reason' => $reason]);
    }

    public function unsuspend(Organization $organization, User $admin): void
    {
        $organization->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        $this->gate->forget($organization);
        $this->audit->log('organization.unsuspended', $organization, $admin, $organization);
    }
}
