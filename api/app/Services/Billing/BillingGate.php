<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/** Punto único para saber qué le permite el plan a una empresa. Se comparte por petición (binding "scoped"). */
class BillingGate
{
    /** @var array<int, Entitlements> */
    private array $cache = [];

    public function for(Organization $organization): Entitlements
    {
        return $this->cache[$organization->id] ??= $this->resolve($organization);
    }

    /**
     * ¿El plan atiende a este agente? Con más agentes activos de los que permite el plan (p. ej. tras bajar de plan)
     * solo atienden los más antiguos: así bajar de plan no deja agentes "de regalo".
     */
    public function agentServes(Agent $agent, Entitlements $entitlements): bool
    {
        if (! $agent->is_active || ! $entitlements->serves()) {
            return false;
        }

        return Agent::where('is_active', true)->orderBy('id')->limit($entitlements->maxAgents())->pluck('id')->contains($agent->id);
    }

    /** Tras cambiar suscripción o suspensión dentro de la misma petición. */
    public function forget(Organization $organization): void
    {
        unset($this->cache[$organization->id]);
        $organization->unsetRelation('subscription');
    }

    /** @throws SubscriptionInactive|QuotaExceeded */
    public function assertCanAnswer(Organization $organization): Entitlements
    {
        $entitlements = $this->for($organization);

        if (! $entitlements->serves()) {
            throw new SubscriptionInactive($entitlements->state);
        }
        if ($entitlements->quotaExceeded()) {
            throw new QuotaExceeded;
        }

        return $entitlements;
    }

    private function resolve(Organization $organization): Entitlements
    {
        $subscription = $organization->subscription()->with('plan')->first();
        $now = CarbonImmutable::now();
        $period = $this->period($subscription, $now);

        return new Entitlements(
            organization: $organization,
            subscription: $subscription,
            plan: $subscription?->plan,
            state: $this->state($organization, $subscription, $now),
            periodStart: $period['periodStart'],
            periodEnd: $period['periodEnd'],
        );
    }

    private function state(Organization $organization, ?Subscription $subscription, CarbonImmutable $now): string
    {
        if ($organization->isSuspended()) {
            return Entitlements::SUSPENDED;
        }
        if (! $subscription) {
            return Entitlements::NONE;
        }

        return match ($subscription->status) {
            SubscriptionStatus::Trialing => ($subscription->trial_ends_at ?? $subscription->current_period_end)?->isFuture()
                ? Entitlements::TRIALING : Entitlements::EXPIRED,
            // Una cortesía del superadmin puede tener fecha de fin; las de Stripe se rigen solo por su estado.
            SubscriptionStatus::Active => $subscription->source === 'manual' && $subscription->current_period_end?->isPast()
                ? Entitlements::EXPIRED : Entitlements::ACTIVE,
            SubscriptionStatus::PastDue => Entitlements::PAST_DUE,
            SubscriptionStatus::Unpaid => Entitlements::UNPAID,
            SubscriptionStatus::Canceled => Entitlements::CANCELED,
            SubscriptionStatus::Incomplete => Entitlements::INCOMPLETE,
        };
    }

    /**
     * Período sobre el que se cuenta el cupo de mensajes: el que informa Stripe (o la prueba); en cualquier otro
     * caso, el mes calendario. Las cortesías siempre usan el mes calendario: su fecha es de vencimiento, no de ciclo.
     *
     * @return array{periodStart: CarbonImmutable, periodEnd: CarbonImmutable}
     */
    private function period(?Subscription $subscription, CarbonImmutable $now): array
    {
        if ($subscription && $subscription->source !== 'manual' && $subscription->current_period_start && $subscription->current_period_end) {
            $start = CarbonImmutable::instance($subscription->current_period_start);
            $end = CarbonImmutable::instance($subscription->current_period_end);

            if ($start <= $now && $now < $end) {
                return ['periodStart' => $start, 'periodEnd' => $end];
            }
        }

        return ['periodStart' => $now->startOfMonth(), 'periodEnd' => $now->startOfMonth()->addMonth()];
    }
}
