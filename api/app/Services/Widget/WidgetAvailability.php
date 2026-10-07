<?php

namespace App\Services\Widget;

use App\Models\Agent;
use App\Services\Billing\BillingGate;

/** ¿Puede este agente atender ahora a quien lo pide desde ese sitio? */
class WidgetAvailability
{
    public const INACTIVE = 'inactive';

    public const UNAVAILABLE = 'unavailable';   // plan vencido, impago o suspendida

    public const PLAN_LIMIT = 'plan_limit';     // más agentes activos de los que permite el plan

    public const ORIGIN = 'origin';             // el sitio no está en la lista de dominios permitidos

    public const QUOTA = 'quota';               // se agotaron los mensajes del período

    public function __construct(private readonly BillingGate $gate) {}

    /** @return string|null El motivo por el que NO se puede atender, o null si sí. */
    public function blockedReason(Agent $agent, ?string $origin, bool $considerQuota = true): ?string
    {
        if (! $agent->is_active) {
            return self::INACTIVE;
        }

        $entitlements = $this->gate->for($agent->organization);
        if (! $entitlements->serves()) {
            return self::UNAVAILABLE;
        }
        if (! $this->gate->agentServes($agent, $entitlements)) {
            return self::PLAN_LIMIT;
        }
        if (! $this->originAllowed($agent, $origin)) {
            return self::ORIGIN;
        }
        if ($considerQuota && $entitlements->quotaExceeded()) {
            return self::QUOTA;
        }

        return null;
    }

    /**
     * Lista de sitios permitidos. Sin cabecera Origin (petición del mismo origen o fuera de un navegador) se permite:
     * esto frena que OTROS sitios incrusten tu agente y gasten tus mensajes, no es autenticación (un cliente que no es
     * un navegador puede falsear Origin; para eso están los límites de uso).
     */
    public function originAllowed(Agent $agent, ?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false; // «Origin: null» (iframes con sandbox, file://) y similares
        }
        $host = strtolower($host);

        // El propio panel (vista previa del widget) siempre puede.
        if ($host === strtolower((string) parse_url(config('app.frontend_url'), PHP_URL_HOST))) {
            return true;
        }

        foreach ($agent->allowed_domains ?? [] as $domain) {
            // `midominio.com` permite el dominio y todos sus subdominios (www., tienda., …), nunca "otromidominio.com".
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
