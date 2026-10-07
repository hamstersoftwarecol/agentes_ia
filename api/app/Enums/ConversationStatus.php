<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Ai = 'ai';             // atiende la IA
    case Waiting = 'waiting';   // se pidió una persona y nadie la ha tomado todavía: la IA calla
    case Human = 'human';       // una persona la tomó: la IA calla
    case Closed = 'closed';     // resuelta

    /** Solo en este estado el agente de IA responde al visitante. */
    public function aiAnswers(): bool
    {
        return $this === self::Ai;
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
