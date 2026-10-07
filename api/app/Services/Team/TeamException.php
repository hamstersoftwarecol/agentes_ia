<?php

namespace App\Services\Team;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Una regla del equipo que no se cumple. Con `$field` responde como error de validación de ese campo. */
class TeamException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            $this->field ? ['message' => $this->getMessage(), 'errors' => [$this->field => [$this->getMessage()]]] : ['message' => $this->getMessage()],
            $this->status,
        );
    }
}
