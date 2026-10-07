<?php

namespace App\Policies;

use App\Models\User;

class ConversationPolicy extends OrganizationManagedPolicy
{
    /** Atender (tomar, responder, devolver, cerrar) es trabajo de todo el equipo, no solo de los administradores. */
    public function respond(User $user): bool
    {
        return $this->isMember($user);
    }
}
