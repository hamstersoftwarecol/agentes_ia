<?php

namespace App\Services\AI;

use RuntimeException;
use Throwable;

/** Fallo al hablar con un proveedor de IA. El mensaje es para logs, nunca para el cliente final. */
class AIProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
