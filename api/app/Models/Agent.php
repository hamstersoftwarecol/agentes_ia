<?php

namespace App\Models;

use App\Enums\AgentLanguage;
use App\Enums\AgentTone;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Agent extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'role',
        'goal',
        'tone',
        'language',
        'instructions',
        'welcome_message',
        'is_active',
        'widget_position',
        'widget_color',
        'allowed_domains',
        'knowledge_base_id',
    ];

    /** Mismos valores por defecto que la tabla, para que el modelo recién creado ya los tenga. */
    protected $attributes = [
        'tone' => 'friendly_professional',
        'language' => 'es',
        'is_active' => true,
        'widget_position' => 'right',
        'widget_color' => '#b45309',
        'allowed_domains' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'tone' => AgentTone::class,
            'language' => AgentLanguage::class,
            'is_active' => 'boolean',
            'allowed_domains' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            $agent->public_id ??= self::generatePublicId();
        });
    }

    public static function generatePublicId(): string
    {
        do {
            $id = 'ag_'.Str::random(10);
        } while (static::withoutGlobalScopes()->where('public_id', $id)->exists());

        return $id;
    }

    /** Laravel acota `{conversation}` a este agente en las rutas anidadas del widget. */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
