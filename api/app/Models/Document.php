<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use BelongsToOrganization, HasFactory;

    /** Los archivos son privados: viven en el disco `local`, nunca en `public`. */
    public const DISK = 'local';

    protected $fillable = ['title', 'content'];

    /** Mismos valores por defecto que la tabla, para que un documento recién creado ya los tenga. */
    protected $attributes = ['chunk_count' => 0];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Document $document) {
            if ($document->file_path) {
                Storage::disk(self::DISK)->delete($document->file_path);
            }
        });
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /** Texto que se trocea y se convierte en vectores. */
    public function indexableText(): string
    {
        $content = trim((string) $this->content);

        return $this->type === DocumentType::Faq
            ? "Pregunta: {$this->title}\nRespuesta: {$content}"
            : $content;
    }
}
