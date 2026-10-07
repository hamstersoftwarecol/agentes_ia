<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'max_agents' => $this->max_agents,
            'max_monthly_messages' => $this->max_monthly_messages,
            'max_sites' => $this->max_sites,
            'max_users' => $this->max_users,
            'features' => [
                'human_inbox' => $this->hasFeature('human_inbox'),
                'widget_customization' => $this->hasFeature('widget_customization'),
                'analytics' => $this->hasFeature('analytics'),
            ],
            // Si se puede contratar desde la app (tiene precio en Stripe). No se expone el ID del precio.
            'purchasable' => filled($this->stripe_price_id),
        ];
    }
}
