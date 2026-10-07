<?php

namespace App\Http\Resources;

use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeBase */
class KnowledgeBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'documents_count' => $this->whenCounted('documents'),
            'ready_documents_count' => $this->whenCounted('readyDocuments'),
            'created_at' => $this->created_at,
        ];
    }
}
