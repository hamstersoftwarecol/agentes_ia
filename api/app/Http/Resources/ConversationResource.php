<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    private bool $withMessages = false;

    public function withMessages(): static
    {
        $this->withMessages = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'agent_name' => $this->agent_name,
            'contact' => ['id' => $this->contact->id, 'name' => $this->contact->displayName()],
            'status' => $this->status->value,
            'handoff_reason' => $this->handoff_reason,
            'handoff_at' => $this->handoff_at,
            'closed_at' => $this->closed_at,
            'assigned_to' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser
                ? ['id' => $this->assignedUser->id, 'name' => $this->assignedUser->name]
                : null),
            'channel' => $this->channel,
            'page_url' => $this->page_url,
            'message_count' => $this->message_count,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'role' => $this->latestMessage->role->value,
                'preview' => Str::limit($this->latestMessage->content, 120),
            ] : null),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? new LeadResource($this->lead) : null),
            'messages' => $this->when($this->withMessages, fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role->value,
                'content' => $m->content,
                'sender_name' => $m->sender_name,
                'model' => $m->model,
                'input_tokens' => $m->input_tokens,
                'output_tokens' => $m->output_tokens,
                'sources' => $m->sources ?? [],
                'created_at' => $m->created_at,
            ])),
        ];
    }
}
