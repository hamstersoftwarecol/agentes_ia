<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgentRequest;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\Billing\BillingGate;
use App\Services\Billing\PlanLimitReached;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentController extends Controller
{
    public function __construct(private readonly BillingGate $gate, private readonly CurrentOrganization $current) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Agent::class);

        return AgentResource::collection(Agent::with('knowledgeBase')->orderBy('name')->orderBy('id')->get());
    }

    public function store(AgentRequest $request): JsonResponse
    {
        if ($request->boolean('is_active', true)) {
            $this->assertRoomForActiveAgent();
        }

        $agent = Agent::create($request->validated());

        return (new AgentResource($agent->load('knowledgeBase')))->response()->setStatusCode(201);
    }

    public function show(Agent $agent): AgentResource
    {
        $this->authorize('view', $agent);

        return new AgentResource($agent->load('knowledgeBase'));
    }

    public function update(AgentRequest $request, Agent $agent): AgentResource
    {
        // Activar un agente cuenta contra el tope del plan; desactivarlo o editarlo, no.
        if ($request->boolean('is_active') && ! $agent->is_active) {
            $this->assertRoomForActiveAgent();
        }

        $agent->update($request->validated());

        return new AgentResource($agent->load('knowledgeBase'));
    }

    public function destroy(Agent $agent)
    {
        $this->authorize('delete', $agent);
        $agent->delete();

        return response()->noContent();
    }

    /** @throws PlanLimitReached */
    private function assertRoomForActiveAgent(): void
    {
        $entitlements = $this->gate->for($this->current->get());

        if (Agent::where('is_active', true)->count() >= $entitlements->maxAgents()) {
            throw PlanLimitReached::agents($entitlements);
        }
    }
}
