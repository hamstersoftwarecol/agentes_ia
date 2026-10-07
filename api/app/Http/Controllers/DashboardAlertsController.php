<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Enums\LeadStatus;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;

/** Contadores baratos para las insignias del menú; se consultan cada pocos segundos. */
class DashboardAlertsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'waiting_conversations' => Conversation::where('status', ConversationStatus::Waiting->value)->count(),
            'new_leads' => Lead::where('status', LeadStatus::New->value)->count(),
        ]);
    }
}
