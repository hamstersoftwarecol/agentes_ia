<?php

namespace App\Services\Agents;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

final readonly class AgentReply
{
    /** @param  Collection<int, DocumentChunk>  $sources */
    public function __construct(
        public string $content,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public Collection $sources,
        public string $systemPrompt,
        public string $action = AgentTurn::ACTION_NONE,
        /** @var array{name: ?string, phone: ?string, email: ?string, interest: ?string}|null */
        public ?array $lead = null,
    ) {}

    public function requestsHuman(): bool
    {
        return $this->action === AgentTurn::ACTION_REQUEST_HUMAN;
    }
}
