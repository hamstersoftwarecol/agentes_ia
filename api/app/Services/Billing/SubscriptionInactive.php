<?php

namespace App\Services\Billing;

use RuntimeException;

/** La empresa no tiene un servicio vigente (prueba vencida, impago, suspensión…). */
class SubscriptionInactive extends RuntimeException
{
    public function __construct(public readonly string $state)
    {
        parent::__construct("La empresa no tiene un plan activo ({$state}).");
    }
}
