<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Configuración de la plataforma, solo lectura: los secretos viven en el entorno y NUNCA se devuelven (solo si están puestos). */
class SettingsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'ai' => [
                'chat_provider' => config('ai.default'),
                'embeddings_provider' => config('ai.embeddings.default'),
                'anthropic' => [
                    'key_set' => filled(config('ai.providers.anthropic.key')),
                    'model' => config('ai.providers.anthropic.model'),
                    'effort' => config('ai.providers.anthropic.effort'),
                ],
                'openai' => ['key_set' => filled(config('ai.embeddings.providers.openai.key')), 'embedding_model' => config('ai.embeddings.providers.openai.model')],
                'pricing' => config('ai.pricing'),
            ],
            'billing' => [
                'stripe_secret_set' => filled(config('billing.stripe.secret')),
                'stripe_webhook_secret_set' => filled(config('billing.stripe.webhook_secret')),
                'trial_days' => config('billing.trial_days'),
                'trial_plan' => config('billing.trial_plan'),
                'infrastructure_cost_usd' => config('billing.infrastructure_cost_usd'),
            ],
            'runtime' => [
                'queue' => config('queue.default'),
                'mail' => config('mail.default'),
                'environment' => app()->environment(),
            ],
        ]);
    }
}
