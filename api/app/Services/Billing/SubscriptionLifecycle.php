<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Notifications\PlanEnded;
use App\Notifications\PlanEnding;
use App\Services\Inbox\StaffNotifier;
use App\Support\CurrentOrganization;

/**
 * Avisos del ciclo de vida de una suscripción con fecha de fin (prueba o plan por cortesía): «termina pronto» una
 * vez, con `billing.trial_ending_days` de antelación, y «terminó» una vez. Se ejecuta a diario (billing:lifecycle) y es
 * idempotente: las marcas `ending_notified_at` / `ended_notified_at` evitan repetir; extender la prueba las limpia.
 *
 * Las suscripciones de Stripe no pasan por aquí: sus avisos (cobro fallido, cancelación) llegan por webhook.
 */
class SubscriptionLifecycle
{
    /** Columna que guarda la fecha de fin según el origen de la suscripción. */
    private const END_COLUMN = ['trial' => 'trial_ends_at', 'manual' => 'current_period_end'];

    public function __construct(private readonly StaffNotifier $notifier, private readonly CurrentOrganization $current) {}

    /** @return array{ending: int, ended: int} */
    public function run(): array
    {
        $sent = ['ending' => 0, 'ended' => 0];
        $until = now()->addDays((int) config('billing.trial_ending_days'));
        $since = now()->subDays((int) config('billing.trial_ended_notice_days'));

        foreach (self::END_COLUMN as $source => $column) {
            Subscription::with('organization')
                ->where('source', $source)
                ->whereBetween($column, [$since, $until])
                ->where(fn ($q) => $q->whereNull('ending_notified_at')->orWhereNull('ended_notified_at'))
                ->chunkById(100, function ($subscriptions) use ($source, $column, &$sent) {
                    foreach ($subscriptions as $subscription) {
                        $this->process($subscription, $source, $subscription->{$column}, $sent);
                    }
                });
        }

        $this->current->set(null);

        return $sent;
    }

    /** @param  array{ending: int, ended: int}  $sent */
    private function process(Subscription $subscription, string $kind, $endsAt, array &$sent): void
    {
        $organization = $subscription->organization;
        if (! $organization || $organization->isSuspended()) {
            return;
        }

        $this->current->set($organization);

        if ($endsAt->isFuture() && ! $subscription->ending_notified_at) {
            $subscription->forceFill(['ending_notified_at' => now()])->save();
            $this->notifier->billing(new PlanEnding(
                $kind,
                $organization->name,
                max(1, (int) ceil(now()->diffInDays($endsAt))),
                $endsAt->locale('es')->isoFormat('D [de] MMMM'),
            ));
            $sent['ending']++;
        } elseif ($endsAt->isPast() && ! $subscription->ended_notified_at) {
            // Si el aviso de «termina pronto» no llegó a darse (empresa creada con poco margen), ya no tiene sentido.
            $subscription->forceFill(['ended_notified_at' => now(), 'ending_notified_at' => $subscription->ending_notified_at ?? now()])->save();
            $this->notifier->billing(new PlanEnded($kind, $organization->name));
            $sent['ended']++;
        }
    }
}
