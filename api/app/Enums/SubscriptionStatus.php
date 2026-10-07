<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';     // cobro fallido: Stripe sigue reintentando
    case Unpaid = 'unpaid';        // Stripe se rindió: sin servicio
    case Canceled = 'canceled';
    case Incomplete = 'incomplete'; // el primer pago no se completó

    /** Estados de Stripe que no tienen equivalente directo se tratan como la variante más cercana. */
    public static function fromStripe(string $status): self
    {
        return match ($status) {
            'trialing' => self::Trialing,
            'active' => self::Active,
            'past_due' => self::PastDue,
            'unpaid', 'paused' => self::Unpaid,
            'canceled', 'incomplete_expired' => self::Canceled,
            default => self::Incomplete,
        };
    }
}
