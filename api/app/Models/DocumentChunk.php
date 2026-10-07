<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    /** El vector pesa ~6 KB por fragmento: nunca se serializa en respuestas. */
    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
