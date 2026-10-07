<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    protected $fillable = [
        'name',
        'website',
        'email',
        'phone',
        'address',
        'country',
        'timezone',
        'business_hours',
    ];

    protected function casts(): array
    {
        return ['business_hours' => 'array', 'suspended_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /** Horario por defecto: lunes a viernes de 9:00 a 18:00. */
    public static function defaultBusinessHours(): array
    {
        return collect(self::DAYS)->mapWithKeys(fn (string $day) => [
            $day => [
                'enabled' => ! in_array($day, ['saturday', 'sunday'], true),
                'open' => '09:00',
                'close' => '18:00',
            ],
        ])->all();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $slug = $base;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    public function roleOf(User $user): ?OrganizationRole
    {
        $role = $this->users()->whereKey($user->getKey())->first()?->pivot->role;

        return $role ? OrganizationRole::from($role) : null;
    }
}
