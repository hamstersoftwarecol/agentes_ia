<?php

namespace App\Services\Widget;

use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agents\AgentChat;
use App\Services\AI\AIProviderException;
use App\Services\AI\ChatMessage;
use App\Services\Billing\BillingGate;
use App\Services\Billing\QuotaAlerts;
use App\Services\Billing\QuotaExceeded;
use App\Services\Billing\SubscriptionInactive;
use App\Services\Billing\UsageRecorder;
use App\Services\Inbox\ConversationWorkflow;
use App\Services\Inbox\LeadCapture;
use App\Services\Inbox\StaffNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Un mensaje del visitante: lo contesta la IA (con RAG, leads y paso a una persona) o, si ya la atiende una persona, solo se guarda. */
class WidgetChat
{
    public function __construct(
        private readonly AgentChat $chat,
        private readonly ConversationWorkflow $workflow,
        private readonly LeadCapture $leads,
        private readonly StaffNotifier $notifier,
        private readonly BillingGate $gate,
        private readonly UsageRecorder $usage,
        private readonly QuotaAlerts $quotaAlerts,
    ) {}

    /**
     * @throws ConversationLimitReached
     * @throws AIProviderException
     * @throws SubscriptionInactive
     * @throws QuotaExceeded
     */
    public function send(Agent $agent, string $visitorId, ?Conversation $conversation, string $text, ?string $pageUrl): WidgetTurn
    {
        // Una conversación cerrada (resuelta) no se reabre por escribir de nuevo: empieza una nueva con contexto limpio.
        if ($conversation?->status === ConversationStatus::Closed) {
            $conversation = null;
        }

        if ($conversation && $conversation->message_count >= config('widget.max_messages_per_conversation')) {
            throw new ConversationLimitReached;
        }

        $organization = $agent->organization;

        // En espera o con una persona la IA calla: el mensaje se guarda para que lo lea el equipo (no gasta cupo).
        if ($conversation && ! $conversation->status->aiAnswers()) {
            $entitlements = $this->gate->for($organization);
            if (! $entitlements->serves()) {
                throw new SubscriptionInactive($entitlements->state);
            }

            return $this->storeWithoutReply($conversation, $text);
        }

        // Antes de gastar dinero en el modelo: plan vigente y mensajes disponibles.
        $this->gate->assertCanAnswer($organization);

        // El historial sale de la base de datos, nunca del cliente: un visitante no puede
        // inventarse mensajes "del agente" ni inyectar contexto.
        $history = $conversation
            ? $conversation->messages()->reorder('id', 'desc')->limit((int) config('widget.history_messages'))->get()->reverse()
            : collect();

        $messages = $history
            ->map(fn (Message $m) => new ChatMessage($m->role === MessageRole::User ? 'user' : 'assistant', $m->content))
            ->push(ChatMessage::user($text))
            ->values()
            ->all();

        // La llamada al modelo va ANTES de guardar: si falla no queda un mensaje huérfano sin respuesta
        // y el visitante puede reintentar sin duplicados.
        $reply = $this->chat->reply($agent, $messages);

        $notifyHandoff = false;
        $newLead = null;

        $turn = DB::transaction(function () use ($agent, $visitorId, $conversation, $text, $pageUrl, $reply, &$notifyHandoff, &$newLead) {
            $contact = Contact::firstOrCreate(['visitor_id' => $visitorId]);
            $contact->forceFill(['last_seen_at' => now()])->save();

            $conversation ??= Conversation::create([
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'contact_id' => $contact->id,
                'status' => ConversationStatus::Ai,
                'page_url' => $this->cleanUrl($pageUrl),
            ]);

            $userMessage = Message::create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => $text]);

            $assistant = Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Assistant,
                'content' => $reply->content,
                'model' => $reply->model,
                'input_tokens' => $reply->inputTokens,
                'output_tokens' => $reply->outputTokens,
                'sources' => $reply->sources->map(fn ($chunk) => [
                    'document_id' => $chunk->document_id,
                    'title' => $chunk->document->title,
                    'similarity' => round($chunk->similarity, 4),
                ])->values()->all(),
            ]);

            $conversation->forceFill([
                'message_count' => $conversation->message_count + 2,
                'last_message_at' => now(),
            ])->save();

            // Cada respuesta de IA a un cliente descuenta del cupo del plan y suma al costo de la empresa.
            $this->usage->chat('widget', $agent->id, $conversation->id, $reply->model, $reply->inputTokens, $reply->outputTokens);

            if ($reply->requestsHuman()) {
                $notifyHandoff = $this->workflow->requestHuman($conversation, 'ai');
            }

            if ($reply->lead) {
                [$lead, $created] = $this->leads->capture($conversation, $contact, $agent, $reply->lead);
                $newLead = $created ? $lead : null;
            }

            return new WidgetTurn($conversation, $userMessage, $assistant);
        });

        $this->quotaAlerts->check($organization);

        // Los avisos van fuera de la transacción y solo cuando algo cambió: handoff nuevo o lead nuevo.
        if ($notifyHandoff) {
            $this->notifier->handoffRequested($turn->conversation);
        }
        if ($newLead) {
            $this->notifier->leadCaptured($newLead);
        }

        return $turn;
    }

    private function storeWithoutReply(Conversation $conversation, string $text): WidgetTurn
    {
        return DB::transaction(function () use ($conversation, $text) {
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => MessageRole::User, 'content' => $text]);

            $conversation->forceFill(['message_count' => $conversation->message_count + 1, 'last_message_at' => now()])->save();
            $conversation->contact->forceFill(['last_seen_at' => now()])->save();

            return new WidgetTurn($conversation, $message, null);
        });
    }

    /** Solo origen + ruta: las query strings y fragmentos pueden traer datos personales o tokens. */
    private function cleanUrl(?string $url): ?string
    {
        $parts = $url ? parse_url($url) : false;
        if (! $parts || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            return null;
        }

        $clean = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');

        return Str::limit($clean, 500, '');
    }
}
