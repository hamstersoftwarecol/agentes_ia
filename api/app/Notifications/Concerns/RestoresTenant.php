<?php

namespace App\Notifications\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Una notificación en cola se ejecuta en un worker, sin petición ni usuario: no hay empresa actual y los
 * modelos de negocio (con su filtro por empresa) devolverían vacío. Antes de leer relaciones se restaura
 * el contexto desde el propio modelo notificado.
 */
trait RestoresTenant
{
    protected function restoreTenant(Model $model): void
    {
        app(CurrentOrganization::class)->set(Organization::find($model->organization_id));
    }
}
