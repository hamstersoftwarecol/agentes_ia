<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Widget\WidgetAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API pública del widget: sin sesión ni cookies, se identifica por agente (public_id). */
class WidgetController extends Controller
{
    public function config(Request $request, Agent $widgetAgent, WidgetAvailability $availability): JsonResponse
    {
        // Sin servicio (agente inactivo, plan vencido, sitio no permitido, cupo agotado) el widget no se muestra.
        $reason = $availability->blockedReason($widgetAgent, $request->header('Origin'));
        // Solo se revela el motivo del sitio no permitido: es lo que el dueño necesita para configurarlo.
        $payload = $reason !== null ? ['active' => false] + ($reason === WidgetAvailability::ORIGIN ? ['reason' => 'origin'] : []) : [
            'active' => true,
            'name' => $widgetAgent->name,
            'company' => $widgetAgent->organization->name,
            'logo_url' => $widgetAgent->organization->logo_url,
            'welcome_message' => $widgetAgent->welcome_message,
            'position' => $widgetAgent->widget_position,
            'color' => $widgetAgent->widget_color,
        ];

        return response()->json($payload)->header('Cache-Control', 'public, max-age=60');
    }
}
