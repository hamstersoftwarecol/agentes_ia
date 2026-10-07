<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgentTestChatRequest;
use App\Models\Agent;
use App\Services\Agents\AgentChat;
use App\Services\AI\ChatMessage;
use App\Services\Billing\BillingGate;
use App\Services\Billing\PlanLimitReached;
use App\Services\Billing\UsageRecorder;
use Illuminate\Http\JsonResponse;

/** AI-008: probar el agente desde el panel. Sin persistencia; las conversaciones reales llegan en el sprint de chat. */
class AgentTestChatController extends Controller
{
    public function store(AgentTestChatRequest $request, Agent $agent, AgentChat $chat, BillingGate $gate, UsageRecorder $usage): JsonResponse
    {
        // Probar el agente también gasta modelo: sin plan vigente no se puede.
        $entitlements = $gate->for($agent->organization);
        if (! $entitlements->serves()) {
            throw PlanLimitReached::inactive($entitlements);
        }

        $messages = array_map(
            fn (array $m) => new ChatMessage($m['role'], $m['content']),
            $request->validated('messages'),
        );

        $reply = $chat->reply($agent, $messages);
        $usage->chat('test', $agent->id, null, $reply->model, $reply->inputTokens, $reply->outputTokens); // no cuenta contra el cupo

        return response()->json([
            'reply' => $reply->content,
            'action' => $reply->action,
            'lead' => $reply->lead,
            'model' => $reply->model,
            'usage' => ['input_tokens' => $reply->inputTokens, 'output_tokens' => $reply->outputTokens],
            'sources' => $reply->sources->map(fn ($chunk) => [
                'document_id' => $chunk->document_id,
                'title' => $chunk->document->title,
                'similarity' => round($chunk->similarity, 4),
                'excerpt' => mb_substr($chunk->content, 0, 280),
            ])->values(),
            'system_prompt' => $reply->systemPrompt,
        ]);
    }
}
