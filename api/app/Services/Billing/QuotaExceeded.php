<?php

namespace App\Services\Billing;

use RuntimeException;

class QuotaExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Se agotaron los mensajes del plan para este período.');
    }
}
