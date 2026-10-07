<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = ['input_tokens' => 0, 'output_tokens' => 0];

    protected function casts(): array
    {
        return ['role' => MessageRole::class, 'sources' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // La tabla no usa timestamps de Eloquent: la fecha se fija aquí, en UTC, no en el servidor de BD.
        static::creating(fn (Message $message) => $message->created_at ??= now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
