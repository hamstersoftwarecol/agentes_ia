<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Enums\LeadStatus;
use App\Http\Resources\LeadResource;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardSummaryController extends Controller
{
    private const DAYS = 30;

    public function __invoke(CurrentOrganization $current): JsonResponse
    {
        $timezone = $current->get()->timezone;

        // Ventana de 30 días calendario en la zona horaria de la empresa (hoy incluido), expresada en UTC
        // porque así se guardan las fechas.
        $firstDay = Carbon::now($timezone)->subDays(self::DAYS - 1)->startOfDay();
        $since = $firstDay->clone()->utc();

        // Los modelos ya vienen acotados a la empresa actual por su global scope.
        $perDay = Conversation::where('created_at', '>=', $since)
            ->selectRaw("(created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as day, count(*) as total", [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $daily = collect(range(0, self::DAYS - 1))->map(function (int $i) use ($firstDay, $perDay) {
            $date = $firstDay->clone()->addDays($i)->toDateString();

            return ['date' => $date, 'conversations' => (int) ($perDay[$date] ?? 0)];
        })->all();

        return response()->json([
            'conversations_30d' => Conversation::where('created_at', '>=', $since)->count(),
            'messages_30d' => Message::where('created_at', '>=', $since)->count(),
            'leads_30d' => Lead::where('created_at', '>=', $since)->count(),
            'tokens_30d' => (int) Message::where('created_at', '>=', $since)->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as total')->value('total'),
            'conversations_total' => Conversation::count(),
            // Lo que pide acción del equipo (se muestra como insignia en el menú).
            'waiting_conversations' => Conversation::where('status', ConversationStatus::Waiting->value)->count(),
            'new_leads' => Lead::where('status', LeadStatus::New->value)->count(),
            'daily' => $daily,
            'recent_leads' => LeadResource::collection(Lead::orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()),
        ]);
    }
}
