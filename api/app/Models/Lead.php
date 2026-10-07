<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'new', 'source' => 'ai'];

    protected function casts(): array
    {
        return ['status' => LeadStatus::class];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
