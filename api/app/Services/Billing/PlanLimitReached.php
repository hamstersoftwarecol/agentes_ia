<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Una acción que el plan actual no permite (tope de agentes, función no incluida, plan inactivo). Responde 402. */
class PlanLimitReached extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function agents(Entitlements $entitlements): self
    {
        return $entitlements->serves()
            ? new self("Tu plan permite {$entitlements->maxAgents()} agente".($entitlements->maxAgents() === 1 ? '' : 's').' activos. Desactiva uno o sube de plan.', 'agents')
            : self::inactive($entitlements);
    }

    public static function users(Entitlements $entitlements): self
    {
        $max = $entitlements->maxUsers();

        return new self("Tu plan permite {$max} usuario".($max === 1 ? '' : 's').'. Sube de plan para sumar más personas al equipo.', 'users');
    }

    public static function feature(string $feature, string $message): self
    {
        return new self($message, $feature);
    }

    public static function inactive(Entitlements $entitlements): self
    {
        return new self(match ($entitlements->state) {
            Entitlements::SUSPENDED => 'Tu cuenta está suspendida. Contacta a soporte.',
            Entitlements::EXPIRED, Entitlements::NONE => 'Tu período de prueba terminó. Elige un plan para seguir.',
            default => 'Tu suscripción no está activa. Revisa la facturación para continuar.',
        }, 'inactive');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'plan_limit', 'reason' => $this->reason], 402);
    }
}
