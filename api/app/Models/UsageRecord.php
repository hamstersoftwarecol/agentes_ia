<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (UsageRecord $record) => $record->created_at ??= now());
    }
}
