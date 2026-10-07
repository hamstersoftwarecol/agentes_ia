<?php

namespace App\Policies;

use App\Models\User;

class LeadPolicy extends OrganizationManagedPolicy
{
    /** Trabajar los leads (estado, notas, datos) es de todo el equipo; borrarlos, de propietario y administradores. */
    public function update(User $user): bool
    {
        return $this->isMember($user);
    }
}
