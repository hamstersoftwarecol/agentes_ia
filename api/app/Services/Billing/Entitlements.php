<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;

/**
 * Lo que una empresa puede hacer ahora mismo: estado de su suscripción, topes del plan y consumo del período.
 * Se calcula en BillingGate::for(); nadie más debería interpretar `subscriptions.status` por su cuenta.
 */
class Entitlements
{
    public const ACTIVE = 'active';

    public const TRIALING = 'trialing';

    public const PAST_DUE = 'past_due';

    public const EXPIRED = 'expired';       // prueba o cortesía vencida

    public const CANCELED = 'canceled';

    public const UNPAID = 'unpaid';

    public const INCOMPLETE = 'incomplete';

    public const SUSPENDED = 'suspended';   // suspendida por el superadmin

    public const NONE = 'none';             // nunca tuvo suscripción

    private ?int $used = null;

    public function __construct(
        public readonly Organization $organization,
        public readonly ?Subscription $subscription,
        public readonly ?Plan $plan,
        public readonly string $state,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
    ) {}

    /** ¿Los agentes de la empresa pueden atender clientes? */
    public function serves(): bool
    {
        return match ($this->state) {
            self::ACTIVE, self::TRIALING => true,
            self::PAST_DUE => (bool) config('billing.past_due_keeps_service'),
            default => false,
        };
    }

    public function maxAgents(): int
    {
        return $this->serves() ? (int) $this->plan->max_agents : 0;
    }

    /** Sitios distintos donde puede mostrarse el widget; null = sin tope. */
    public function maxSites(): ?int
    {
        return $this->plan?->max_sites;
    }

    /** Personas que pueden pertenecer al equipo. No depende de que el plan esté al día: quita acceso solo el superadmin. */
    public function maxUsers(): int
    {
        return (int) ($this->plan?->max_users ?? 1);
    }

    public function monthlyMessages(): int
    {
        return (int) ($this->plan?->max_monthly_messages ?? 0);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->serves() && (bool) $this->plan?->hasFeature($feature);
    }

    /** Mensajes de IA respondidos a clientes en el período (las pruebas del panel no cuentan). */
    public function messagesUsed(): int
    {
        return $this->used ??= (int) UsageRecord::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('type', 'chat')
            ->where('source', 'widget')
            ->where('created_at', '>=', $this->periodStart)
            ->where('created_at', '<', $this->periodEnd)
            ->sum('messages');
    }

    public function quotaExceeded(): bool
    {
        return $this->messagesUsed() >= $this->monthlyMessages();
    }

    public function usagePercent(): int
    {
        $limit = $this->monthlyMessages();

        return $limit > 0 ? (int) floor($this->messagesUsed() / $limit * 100) : 100;
    }

    public function forgetUsage(): void
    {
        $this->used = null;
    }
}
