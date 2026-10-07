<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Uso de IA: de dónde viene el costo (por modelo y por empresa). */
class UsageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 30);
        $since = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $records = fn () => UsageRecord::withoutGlobalScopes()->where('usage_records.created_at', '>=', $since);

        // sum() de Postgres llega como texto: las cifras se devuelven como números.
        $numbers = fn ($rows) => $rows->map(fn ($row) => collect($row->toArray())
            ->map(fn ($value, $key) => in_array($key, ['messages', 'calls', 'tokens', 'input_tokens', 'output_tokens', 'cost_micros'], true) ? (int) $value : $value)
            ->all());

        return response()->json([
            'days' => $days,
            'by_model' => $numbers($records()
                ->selectRaw('coalesce(model, \'(sin modelo)\') as model, type, sum(messages) as messages, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_micros) as cost_micros')
                ->groupBy('model', 'type')->orderByDesc('cost_micros')->get()),
            'by_organization' => $numbers($records()
                ->join('organizations', 'organizations.id', '=', 'usage_records.organization_id')
                ->selectRaw('organizations.id, organizations.name, sum(usage_records.messages) as messages, sum(usage_records.input_tokens + usage_records.output_tokens) as tokens, sum(usage_records.cost_micros) as cost_micros')
                ->groupBy('organizations.id', 'organizations.name')->orderByDesc('cost_micros')->limit(20)->get()),
            'by_source' => $numbers($records()
                ->selectRaw('source, count(*) as calls, sum(cost_micros) as cost_micros')
                ->groupBy('source')->orderByDesc('cost_micros')->get()),
        ]);
    }
}
