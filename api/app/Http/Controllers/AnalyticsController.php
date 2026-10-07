<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsReport;
use App\Services\Billing\BillingGate;
use App\Services\Billing\PlanLimitReached;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Analíticas del plan Business en adelante. Cualquier miembro del equipo las ve (igual que el resumen). */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, BillingGate $gate, AnalyticsReport $report): JsonResponse
    {
        $organization = $current->get();
        $entitlements = $gate->for($organization);

        if (! $entitlements->serves()) {
            throw PlanLimitReached::inactive($entitlements);
        }
        if (! $entitlements->hasFeature('analytics')) {
            throw PlanLimitReached::feature('analytics', 'Las analíticas están disponibles desde el plan Business.');
        }

        $days = $request->validate(['days' => ['sometimes', Rule::in(AnalyticsReport::PERIODS)]])['days'] ?? 30;

        return response()->json($report->build($organization, (int) $days));
    }
}
