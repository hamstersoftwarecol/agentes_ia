<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Bandeja de conversaciones (CHAT-004). Las acciones de atención están en ConversationActionController. */
class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Conversation::class);

        $filters = $request->validate([
            'agent_id' => ['nullable', 'integer'],
            // `open` = todo lo que no está cerrado.
            'status' => ['nullable', Rule::in([...array_column(ConversationStatus::cases(), 'value'), 'open'])],
        ]);

        return ConversationResource::collection(
            Conversation::with(['contact', 'latestMessage', 'assignedUser', 'lead'])
                ->when($filters['agent_id'] ?? null, fn ($q, $id) => $q->where('agent_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open'
                    ? $q->where('status', '!=', ConversationStatus::Closed->value)
                    : $q->where('status', $status))
                // Quien espera a una persona va primero (el que lleva más tiempo esperando, arriba).
                ->orderByRaw("case when status = 'waiting' then 0 else 1 end")
                ->orderByRaw("case when status = 'waiting' then handoff_at end asc")
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->paginate(20)
        );
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        return (new ConversationResource($conversation->load(['contact', 'messages', 'assignedUser', 'lead'])))->withMessages();
    }
}
