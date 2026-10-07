<?php

namespace App\Services\Widget;

use App\Models\Conversation;
use App\Models\Message;

/** Resultado de un mensaje del visitante. `reply` es null cuando no contestó la IA (la atiende una persona). */
final readonly class WidgetTurn
{
    public function __construct(
        public Conversation $conversation,
        public Message $userMessage,
        public ?Message $reply,
    ) {}
}
