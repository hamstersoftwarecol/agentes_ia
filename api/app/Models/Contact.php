<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            $contact->first_seen_at ??= now();
            $contact->last_seen_at ??= now();
        });
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** Nombre para mostrar: el real si lo dio, o "Visitante #id" mientras sea anónimo. */
    public function displayName(): string
    {
        return $this->name ?: "Visitante #{$this->id}";
    }
}
