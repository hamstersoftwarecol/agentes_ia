<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para todo modelo de negocio con columna `organization_id`:
 * agentes, conocimiento, conversaciones, leads, etc.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->organization_id !== null) {
                return;
            }

            $organizationId = app(CurrentOrganization::class)->id();

            if ($organizationId === null) {
                throw new LogicException(
                    'No se puede crear '.class_basename($model).' sin una empresa actual.'
                );
            }

            $model->organization_id = $organizationId;
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
