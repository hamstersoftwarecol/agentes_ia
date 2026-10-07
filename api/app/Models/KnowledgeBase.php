<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        // Borrado en cascada de la BD no dispara eventos: se borra documento a
        // documento para que también se eliminen sus archivos del disco.
        static::deleting(fn (KnowledgeBase $kb) => $kb->documents()->get()->each->delete());
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function readyDocuments(): HasMany
    {
        return $this->documents()->where('status', DocumentStatus::Ready->value);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
