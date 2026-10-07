<?php

namespace App\Services\Billing\Stripe;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;

/** Lo único que la aplicación necesita de Stripe hacia afuera. Detrás de una interfaz para poder probar sin red. */
interface StripeGateway
{
    public function configured(): bool;

    /** URL de Stripe Checkout para contratar un plan. */
    public function checkoutUrl(Organization $organization, Plan $plan, User $user, ?string $customerId): string;

    /** URL del portal de facturación (método de pago, facturas, cancelar, cambiar de plan). */
    public function portalUrl(string $customerId): string;
}
