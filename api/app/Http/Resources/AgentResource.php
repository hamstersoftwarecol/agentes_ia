<?php

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Agent */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'role' => $this->role,
            'goal' => $this->goal,
            'tone' => $this->tone->value,
            'language' => $this->language->value,
            'instructions' => $this->instructions,
            'welcome_message' => $this->welcome_message,
            'is_active' => $this->is_active,
            'widget_position' => $this->widget_position,
            'widget_color' => $this->widget_color,
            'allowed_domains' => $this->allowed_domains ?? [],
            'knowledge_base_id' => $this->knowledge_base_id,
            'knowledge_base' => $this->whenLoaded('knowledgeBase', fn () => $this->knowledgeBase?->only(['id', 'name'])),
            'created_at' => $this->created_at,
        ];
    }
}
