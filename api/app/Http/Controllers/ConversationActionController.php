<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationReplyRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\Billing\BillingGate;
use App\Services\Billing\PlanLimitReached;
use App\Services\Inbox\ConversationWorkflow;
use App\Services\Inbox\InvalidTransition;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Intervención humana (CHAT-007 y CHAT-008): tomar, responder, devolver a la IA y cerrar. */
class ConversationActionController extends Controller
{
    public function __construct(
        private readonly ConversationWorkflow $workflow,
        private readonly BillingGate $gate,
        private readonly CurrentOrganization $current,
    ) {}

    /** Tomar, responder y devolver a la IA requieren el plan con atención humana; cerrar siempre se puede. */
    private function requireHumanInbox(): void
    {
        if (! $this->gate->for($this->current->get())->hasFeature('human_inbox')) {
            throw PlanLimitReached::feature('human_inbox', 'La atención humana en el chat está disponible desde el plan Business.');
        }
    }

    public function take(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('respond', $conversation);
        $this->requireHumanInbox();
        $this->workflow->take($conversation, $request->user());

        return $this->present($conversation);
    }

    public function reply(ConversationReplyRequest $request, Conversation $conversation): JsonResponse
    {
        $this->requireHumanInbox();
        $this->workflow->reply($conversation, $request->user(), $request->validated('content'));

        return $this->present($conversation)->response()->setStatusCode(201);
    }

    public function release(Request $request, Conversation $conversation): ConversationResource|JsonResponse
    {
        $this->authorize('respond', $conversation);
        $this->requireHumanInbox();

        return $this->guarded(fn () => $this->workflow->release($conversation), $conversation);
    }

    public function close(Request $request, Conversation $conversation): ConversationResource|JsonResponse
    {
        $this->authorize('respond', $conversation);

        return $this->guarded(fn () => $this->workflow->close($conversation), $conversation);
    }

    private function guarded(callable $transition, Conversation $conversation): ConversationResource|JsonResponse
    {
        try {
            $transition();
        } catch (InvalidTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $this->present($conversation);
    }

    private function present(Conversation $conversation): ConversationResource
    {
        return (new ConversationResource($conversation->fresh()->load(['contact', 'messages', 'assignedUser', 'lead'])))->withMessages();
    }
}
