<?php

namespace App\Http\Controllers\Widget;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\WidgetMessageRequest;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIProviderException;
use App\Services\Billing\QuotaExceeded;
use App\Services\Billing\SubscriptionInactive;
use App\Services\Widget\ConversationLimitReached;
use App\Services\Widget\WidgetAvailability;
use App\Services\Widget\WidgetChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetMessageController extends Controller
{
    public function store(WidgetMessageRequest $request, Agent $widgetAgent, WidgetChat $chat, WidgetAvailability $availability): JsonResponse
    {
        // El cupo se comprueba después (solo cuando va a contestar la IA): una conversación con una persona sigue.
        if ($blocked = $this->blocked($availability, $widgetAgent, $request)) {
            return $blocked;
        }

        $conversation = $request->validated('conversation_id')
            ? $this->ownedConversation($widgetAgent, $request->validated('visitor_id'), $request->validated('conversation_id'))
            : null;

        try {
            $turn = $chat->send(
                $widgetAgent,
                $request->validated('visitor_id'),
                $conversation,
                $request->validated('message'),
                $request->validated('page_url'),
            );
        } catch (QuotaExceeded) {
            return response()->json([
                'code' => 'quota_exceeded',
                'message' => 'Este asistente alcanzó su límite de mensajes por ahora. Por favor, contacta directamente a la empresa.',
            ], 403);
        } catch (SubscriptionInactive) {
            return $this->unavailable();
        } catch (ConversationLimitReached) {
            return response()->json([
                'code' => 'conversation_limit',
                'message' => 'Esta conversación alcanzó el límite de mensajes. Recarga la página para iniciar una nueva.',
            ], 429);
        } catch (AIProviderException $e) {
            report($e);

            return response()->json(['message' => 'El asistente no está disponible en este momento. Inténtalo de nuevo en unos minutos.'], 503);
        }

        return response()->json([
            'conversation_id' => $turn->conversation->public_id,
            'user_message_id' => $turn->userMessage->id,
            'status' => $turn->conversation->status->value,
            // null cuando no contestó la IA porque ya la atiende una persona (la respuesta llegará por el historial).
            'message' => $turn->reply ? $this->present($turn->reply) : null,
        ], 201);
    }

    /**
     * Mensajes de la conversación del visitante: sirve para restaurar el historial al recargar y, con
     * `?after={id}`, para sondear mensajes nuevos (respuestas de una persona). Incluye el estado, que el
     * widget usa para avisar "te atiende una persona".
     */
    public function index(Request $request, Agent $widgetAgent, Conversation $conversation, WidgetAvailability $availability): JsonResponse
    {
        if (! $availability->originAllowed($widgetAgent, $request->header('Origin'))) {
            return $this->originNotAllowed();
        }

        $visitorId = (string) $request->header('X-Visitor-Id');
        abort_unless(Str::isUuid($visitorId), 422, 'Identificador de visitante inválido.');

        $this->assertOwns($conversation, $widgetAgent, $visitorId);

        $after = max(0, (int) $request->query('after', 0));
        $conversation->load('assignedUser');

        return response()->json([
            'status' => $conversation->status->value,
            'agent_name' => $conversation->assignedUser ? Str::before(trim($conversation->assignedUser->name), ' ') : null,
            'messages' => $conversation->messages()->where('id', '>', $after)->get()->map(fn (Message $m) => $this->present($m)),
        ]);
    }

    private function blocked(WidgetAvailability $availability, Agent $agent, Request $request): ?JsonResponse
    {
        return match ($availability->blockedReason($agent, $request->header('Origin'), considerQuota: false)) {
            null => null,
            WidgetAvailability::ORIGIN => $this->originNotAllowed(),
            default => $this->unavailable(),
        };
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['code' => 'unavailable', 'message' => 'Este asistente no está disponible en este momento.'], 403);
    }

    private function originNotAllowed(): JsonResponse
    {
        return response()->json(['code' => 'origin_not_allowed', 'message' => 'Este sitio no está autorizado para usar el asistente.'], 403);
    }

    private function ownedConversation(Agent $agent, string $visitorId, string $publicId): Conversation
    {
        $conversation = Conversation::where('public_id', $publicId)->with('contact')->first();
        abort_if(! $conversation, 404, 'Conversación no encontrada.');
        $this->assertOwns($conversation, $agent, $visitorId);

        return $conversation;
    }

    /** Solo el visitante que la inició (visitor_id secreto) y solo a través del mismo agente. */
    private function assertOwns(Conversation $conversation, Agent $agent, string $visitorId): void
    {
        $owns = $conversation->agent_id === $agent->id
            && hash_equals($conversation->contact->visitor_id, strtolower($visitorId));

        abort_unless($owns, 404, 'Conversación no encontrada.');
    }

    /** Lo único que ve el visitante: sin modelo, tokens ni fuentes internas. */
    private function present(Message $message): array
    {
        return array_filter([
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            // Las respuestas de una persona llevan su nombre de pila.
            'sender_name' => $message->role === MessageRole::Human ? $message->sender_name : null,
            'created_at' => $message->created_at,
        ], fn ($value) => $value !== null);
    }
}
