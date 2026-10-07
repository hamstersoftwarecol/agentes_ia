<?php

namespace App\Services\Inbox;

use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Estados de una conversación y quién puede moverla:
 *
 *   ai ──(el cliente o el agente piden una persona)──► waiting ──(alguien la toma / responde)──► human
 *    ▲                                                     │                                       │
 *    └──────────────(devolver a la IA)─────────────────────┴───────────────────────────────────────┤
 *   cualquier estado ──(cerrar)──► closed ──(una persona responde)──► human
 *
 * Solo en `ai` contesta el agente de IA.
 */
class ConversationWorkflow
{
    /** El agente (o el cliente) pide una persona. No hace nada si ya la atiende una o está cerrada. */
    public function requestHuman(Conversation $conversation, string $reason): bool
    {
        if ($conversation->status !== ConversationStatus::Ai) {
            return false;
        }

        $conversation->forceFill([
            'status' => ConversationStatus::Waiting,
            'handoff_reason' => $reason,
            'handoff_at' => now(),
            // Marca permanente (release() borra las anteriores): la usan las analíticas.
            'handoff_requested_at' => $conversation->handoff_requested_at ?? now(),
        ])->save();

        return true;
    }

    /** Una persona toma la conversación (también sirve para intervenir mientras atiende la IA o para reabrir). */
    public function take(Conversation $conversation, User $user): void
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Human,
            'assigned_user_id' => $user->id,
            'handoff_at' => $conversation->handoff_at ?? now(),
            'closed_at' => null,
        ])->save();
    }

    /** Se la devuelve al agente de IA. */
    public function release(Conversation $conversation): void
    {
        if (! in_array($conversation->status, [ConversationStatus::Waiting, ConversationStatus::Human], true)) {
            throw new InvalidTransition('Solo se puede devolver a la IA una conversación en espera o atendida por una persona.');
        }

        $conversation->forceFill([
            'status' => ConversationStatus::Ai,
            'assigned_user_id' => null,
            'handoff_reason' => null,
            'handoff_at' => null,
        ])->save();
    }

    /** CHAT-007: marcar como resuelta. */
    public function close(Conversation $conversation): void
    {
        if ($conversation->status === ConversationStatus::Closed) {
            throw new InvalidTransition('La conversación ya está cerrada.');
        }

        $conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();
    }

    /** Respuesta de una persona. Si no la atendía nadie (o estaba cerrada), la toma. */
    public function reply(Conversation $conversation, User $user, string $content): Message
    {
        if ($conversation->status !== ConversationStatus::Human || $conversation->assigned_user_id === null) {
            $this->take($conversation, $user);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Human,
            'user_id' => $user->id,
            // Al visitante solo se le muestra el nombre de pila.
            'sender_name' => Str::limit(Str::before(trim($user->name), ' '), 80, ''),
            'content' => $content,
        ]);

        $conversation->forceFill(['message_count' => $conversation->message_count + 1, 'last_message_at' => now()])->save();

        return $message;
    }
}
