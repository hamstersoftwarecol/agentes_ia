<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    private bool $withContent = false;

    /** El contenido completo solo se envía al abrir un documento (puede pesar cientos de KB). */
    public function withContent(): static
    {
        $this->withContent = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'status' => $this->status->value,
            'error' => $this->error,
            'chunk_count' => $this->chunk_count,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
            'content' => $this->when($this->withContent, $this->content),
        ];
    }
}
