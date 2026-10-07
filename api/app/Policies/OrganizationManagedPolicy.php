<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Política base de los recursos de la empresa (agentes, conocimiento): cualquier
 * miembro puede ver; solo propietario y administradores pueden crear/editar/borrar.
 * El aislamiento entre empresas lo garantiza el global scope, no esta política.
 */
abstract class OrganizationManagedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user): bool
    {
        return $this->canManage($user);
    }

    protected function isMember(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    protected function canManage(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (bool) $user->roleIn($organization)?->canManageOrganization();
    }
}
