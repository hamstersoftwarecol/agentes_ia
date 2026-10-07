<?php

namespace App\Services\Agents;

use App\Contracts\AIProvider;
use App\Models\Agent;
use App\Services\AI\AIProviderException;
use App\Services\AI\ChatMessage;
use App\Services\Billing\BillingGate;
use App\Services\Knowledge\KnowledgeSearch;
use Illuminate\Support\Collection;

/**
 * Un turno del agente: recupera conocimiento → arma el prompt → llama al proveedor de IA con salida
 * estructurada → separa la respuesta para el cliente de las acciones (pasar a una persona, lead).
 */
class AgentChat
{
    public function __construct(
        private readonly AIProvider $ai,
        private readonly KnowledgeSearch $search,
        private readonly AgentPromptBuilder $prompts,
        private readonly AgentTurnParser $parser,
        private readonly BillingGate $gate,
    ) {}

    /** @param  list<ChatMessage>  $messages  Historial; el último es del cliente. */
    public function reply(Agent $agent, array $messages): AgentReply
    {
        $question = collect($messages)->last(fn (ChatMessage $m) => $m->role === 'user')?->content ?? '';

        $sources = $agent->knowledge_base_id && $question !== ''
            ? $this->search->search($agent->knowledgeBase, $question)
            : new Collection;

        // Sin atención humana en el plan, el agente no promete pasar con una persona: recoge los datos de contacto.
        $humanInbox = $this->gate->for($agent->organization)->hasFeature('human_inbox');
        $system = $this->prompts->build($agent, $agent->organization, $sources, $humanInbox);
        $response = $this->ai->chat($messages, $system, AgentTurnParser::schema());

        $turn = $this->parser->parse($response->content);
        if ($turn->reply === '') {
            throw new AIProviderException('El modelo devolvió una respuesta vacía.', retryable: true);
        }

        return new AgentReply(
            content: $turn->reply,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            sources: $sources,
            systemPrompt: $system,
            action: $humanInbox ? $turn->action : AgentTurn::ACTION_NONE,
            lead: $turn->lead,
        );
    }
}
