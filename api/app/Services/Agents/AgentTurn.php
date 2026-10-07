<?php

namespace App\Services\Agents;

/** Lo que decide el agente en un turno: qué decirle al cliente y si hay que actuar. */
final readonly class AgentTurn
{
    public const ACTION_NONE = 'none';

    public const ACTION_REQUEST_HUMAN = 'request_human';

    /**
     * @param  array{name: ?string, phone: ?string, email: ?string, interest: ?string}|null  $lead  Ya normalizado y con al menos un medio de contacto.
     * @param  bool  $structured  false si el modelo no devolvió el JSON esperado (se usó el texto tal cual).
     */
    public function __construct(
        public string $reply,
        public string $action = self::ACTION_NONE,
        public ?array $lead = null,
        public bool $structured = true,
    ) {}

    public function requestsHuman(): bool
    {
        return $this->action === self::ACTION_REQUEST_HUMAN;
    }
}
