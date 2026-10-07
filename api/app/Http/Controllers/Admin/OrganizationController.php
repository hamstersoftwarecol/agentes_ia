<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArrayResource;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\UsageRecord;
use App\Services\Billing\BillingGate;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function __construct(private readonly BillingGate $gate, private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $page = Organization::query()
            ->with('subscription.plan')
            ->withCount('users')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(20);

        return ArrayResource::collection($page->through(fn (Organization $organization) => $this->summary($organization)));
    }

    public function show(Organization $organization): JsonResponse
    {
        $entitlements = $this->gate->for($organization);
        $monthStart = CarbonImmutable::now()->startOfMonth();

        return response()->json($this->summary($organization) + [
            'slug' => $organization->slug,
            'suspension_reason' => $organization->suspension_reason,
            'subscription' => $organization->subscription ? [
                'status' => $organization->subscription->status->value,
                'source' => $organization->subscription->source,
                'trial_ends_at' => $organization->subscription->trial_ends_at,
                'current_period_end' => $organization->subscription->current_period_end,
                'cancel_at_period_end' => $organization->subscription->cancel_at_period_end,
                'stripe_customer_id' => $organization->subscription->stripe_customer_id,
                'stripe_subscription_id' => $organization->subscription->stripe_subscription_id,
            ] : null,
            'period_start' => $entitlements->periodStart,
            'period_end' => $entitlements->periodEnd,
            'ai_cost_micros_month' => (int) UsageRecord::withoutGlobalScopes()->where('organization_id', $organization->id)->where('created_at', '>=', $monthStart)->sum('cost_micros'),
            'members' => $organization->users()->get()->map(fn ($user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role,
            ]),
            'agents' => Agent::withoutGlobalScopes()->where('organization_id', $organization->id)->orderBy('id')->get()->map(fn (Agent $agent) => [
                'id' => $agent->id, 'name' => $agent->name, 'is_active' => $agent->is_active, 'domains' => $agent->allowed_domains ?? [],
            ]),
            'audit' => AuditLog::with('user:id,name')->where('organization_id', $organization->id)->latest('id')->limit(10)->get()
                ->map(fn (AuditLog $log) => $this->auditRow($log)),
        ]);
    }

    /** Plan por cortesía (soporte o acuerdos). `until` vacío = sin vencimiento. */
    public function grantPlan(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'exists:plans,slug'],
            'until' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $this->subscriptions->grant(
            $organization,
            Plan::where('slug', $data['plan'])->firstOrFail(),
            isset($data['until']) ? CarbonImmutable::parse($data['until']) : null,
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->show($organization->refresh());
    }

    public function extendTrial(Request $request, Organization $organization): JsonResponse
    {
        $days = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']])['days'];
        $this->subscriptions->extendTrial($organization, $days, $request->user());

        return $this->show($organization->refresh());
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $this->subscriptions->suspend($organization, $reason, $request->user());

        return $this->show($organization->refresh());
    }

    public function unsuspend(Request $request, Organization $organization): JsonResponse
    {
        $this->subscriptions->unsuspend($organization, $request->user());

        return $this->show($organization->refresh());
    }

    /** @return array<string, mixed> */
    private function summary(Organization $organization): array
    {
        $entitlements = $this->gate->for($organization);
        $owner = $organization->users()->wherePivot('role', 'owner')->first();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'created_at' => $organization->created_at,
            'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
            'members_count' => $organization->users_count ?? $organization->users()->count(),
            'agents_count' => Agent::withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'plan' => $entitlements->plan?->only(['slug', 'name']),
            'state' => $entitlements->state,
            'source' => $entitlements->subscription?->source,
            'messages_used' => $entitlements->messagesUsed(),
            'messages_limit' => $entitlements->monthlyMessages(),
            'suspended' => $organization->isSuspended(),
        ];
    }

    /** @return array<string, mixed> */
    public static function auditRow(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->user?->name,
            'organization_id' => $log->organization_id,
            'organization' => $log->organization?->name,
            'metadata' => $log->metadata,
            'ip' => $log->ip,
            'created_at' => $log->created_at,
        ];
    }
}
