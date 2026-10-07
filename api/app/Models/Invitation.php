<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invitación pendiente para unirse a una empresa. El token en claro solo existe en el correo y en el enlace. */
class Invitation extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_invitations';

    protected $fillable = ['email', 'role', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Búsqueda por token desde fuera de una empresa (quien acepta aún no pertenece a ella). */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScopes()->where('token_hash', static::hashToken($token))->first();
    }
}
