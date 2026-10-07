<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    /**
     * Ingresos − costo de IA − infraestructura = margen, del mes en curso (UTC).
     * Los ingresos son el MRR estimado con el precio de lista de cada plan que paga por Stripe; no incluyen
     * cortesías ni pruebas ni descuentos (la cifra real es la de Stripe).
     */
    public function __invoke(): JsonResponse
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $mrrCents = (int) Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.source', 'stripe')
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->sum('plans.price_cents');

        $usage = UsageRecord::withoutGlobalScopes()->where('created_at', '>=', $monthStart);
        $aiCostMicros = (int) (clone $usage)->sum('cost_micros');
        $aiCostCents = (int) round($aiCostMicros / 10_000);
        $infraCents = (int) round(config('billing.infrastructure_cost_usd') * 100);

        $since = CarbonImmutable::now()->subDays(29)->startOfDay();
        $perDay = UsageRecord::withoutGlobalScopes()
            ->where('created_at', '>=', $since)
            ->selectRaw('created_at::date as day, sum(cost_micros) as micros, sum(messages) as messages')
            ->groupBy('day')->get()->keyBy(fn ($row) => (string) $row->day);

        return response()->json([
            'month' => $monthStart->toDateString(),
            'mrr_cents' => $mrrCents,
            'ai_cost_cents' => $aiCostCents,
            'infrastructure_cost_cents' => $infraCents,
            'margin_cents' => $mrrCents - $aiCostCents - $infraCents,
            'organizations_total' => Organization::count(),
            'organizations_new_30d' => Organization::where('created_at', '>=', CarbonImmutable::now()->subDays(30))->count(),
            'subscriptions' => [
                'paying' => Subscription::where('source', 'stripe')->whereIn('status', ['active', 'past_due'])->count(),
                'trialing' => Subscription::where('status', 'trialing')->count(),
                'manual' => Subscription::where('source', 'manual')->where('status', 'active')->count(),
                'past_due' => Subscription::where('status', 'past_due')->count(),
                'canceled' => Subscription::whereIn('status', ['canceled', 'unpaid'])->count(),
            ],
            'messages_month' => (int) (clone $usage)->where('source', 'widget')->sum('messages'),
            'tokens_month' => (int) (clone $usage)->sum(DB::raw('input_tokens + output_tokens')),
            'daily' => collect(range(0, 29))->map(function (int $i) use ($since, $perDay) {
                $date = $since->addDays($i)->toDateString();

                return ['date' => $date, 'ai_cost_cents' => (int) round(($perDay[$date]->micros ?? 0) / 10_000), 'messages' => (int) ($perDay[$date]->messages ?? 0)];
            })->all(),
        ]);
    }
}
