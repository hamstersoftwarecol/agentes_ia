<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Plan::orderBy('sort_order')->get()->map(fn (Plan $plan) => $this->present($plan)));
    }

    public function update(Request $request, Plan $plan, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'max_agents' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'max_monthly_messages' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'max_sites' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_users' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'features' => ['sometimes', 'array:human_inbox,widget_customization,analytics'],
            'features.*' => ['boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stripe_price_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('plans', 'stripe_price_id')->ignore($plan->id)],
        ]);

        if (isset($data['features'])) {
            $data['features'] = array_merge($plan->features ?? [], $data['features']);
        }

        $plan->fill($data);
        $changes = collect($plan->getDirty())->map(fn ($new, $key) => ['from' => $plan->getOriginal($key), 'to' => $new])->all();
        $plan->save();

        if ($changes) {
            $audit->log('plan.updated', null, $request->user(), $plan, ['plan' => $plan->slug, 'changes' => $changes]);
        }

        return response()->json($this->present($plan));
    }

    /** @return array<string, mixed> */
    private function present(Plan $plan): array
    {
        return $plan->only(['id', 'slug', 'name', 'description', 'price_cents', 'currency', 'stripe_price_id', 'max_agents', 'max_monthly_messages', 'max_sites', 'max_users', 'is_active', 'sort_order'])
            + ['features' => ['human_inbox' => $plan->hasFeature('human_inbox'), 'widget_customization' => $plan->hasFeature('widget_customization'), 'analytics' => $plan->hasFeature('analytics')]]
            + ['subscribers' => $plan->id ? Subscription::where('plan_id', $plan->id)->whereIn('status', ['active', 'trialing', 'past_due'])->count() : 0];
    }
}
