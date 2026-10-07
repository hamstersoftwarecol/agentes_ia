<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lead::class);

        $filters = $request->validate(['status' => ['nullable', Rule::enum(LeadStatus::class)]]);

        return LeadResource::collection(
            Lead::query()
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
        );
    }

    public function show(Lead $lead): LeadResource
    {
        $this->authorize('view', $lead);

        return new LeadResource($lead);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): LeadResource
    {
        $lead->update($request->validated());

        return new LeadResource($lead);
    }

    public function destroy(Lead $lead)
    {
        $this->authorize('delete', $lead);
        $lead->delete();

        return response()->noContent();
    }
}
