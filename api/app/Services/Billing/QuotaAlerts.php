<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Notifications\QuotaThresholdReached;
use App\Services\Inbox\StaffNotifier;

/** Avisa una sola vez por período al llegar al 80 % y al 100 % del cupo de mensajes. */
class QuotaAlerts
{
    public function __construct(private readonly BillingGate $gate, private readonly StaffNotifier $notifier) {}

    public function check(Organization $organization): void
    {
        $this->gate->forget($organization); // el consumo acaba de cambiar
        $entitlements = $this->gate->for($organization);
        $subscription = $entitlements->subscription;
        if (! $subscription || ! $entitlements->plan) {
            return;
        }

        $percent = $entitlements->usagePercent();

        foreach (array_reverse(config('billing.quota_alert_thresholds')) as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $column = "quota_alert_{$threshold}_at";
            $alreadyWarned = $subscription->{$column} && $subscription->{$column} >= $entitlements->periodStart;
            if ($alreadyWarned) {
                return;
            }

            $subscription->forceFill([$column => now()])->save();
            $this->notifier->billing(new QuotaThresholdReached(
                $threshold,
                $entitlements->plan->name,
                $entitlements->messagesUsed(),
                $entitlements->monthlyMessages(),
                $entitlements->periodEnd->locale('es')->isoFormat('D [de] MMMM'),
            ));

            return;
        }
    }
}
