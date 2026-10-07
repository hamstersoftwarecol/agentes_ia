<?php

namespace App\Services\Billing;

use App\Models\UsageRecord;

class UsageRecorder
{
    /**
     * Una respuesta del agente. Solo las de clientes reales (`widget`) cuentan contra el cupo del plan; las pruebas
     * del panel se registran igual, porque también cuestan dinero.
     */
    public function chat(string $source, ?int $agentId, ?int $conversationId, ?string $model, int $inputTokens, int $outputTokens): UsageRecord
    {
        return UsageRecord::create([
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'type' => 'chat',
            'source' => $source,
            'model' => $model,
            'messages' => $source === 'widget' ? 1 : 0,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_micros' => AiPricing::microUsd($model, $inputTokens, $outputTokens),
        ]);
    }

    /** Vectores generados al procesar un documento. */
    public function embedding(?string $model, int $tokens): UsageRecord
    {
        return UsageRecord::create([
            'type' => 'embedding',
            'source' => 'document',
            'model' => $model,
            'input_tokens' => $tokens,
            'cost_micros' => AiPricing::microUsd($model, $tokens, 0),
        ]);
    }
}
