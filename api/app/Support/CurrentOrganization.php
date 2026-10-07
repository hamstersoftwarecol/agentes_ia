<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Empresa (tenant) de la petición actual. Se registra como "scoped" en el
 * contenedor, así que nunca se filtra entre peticiones.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }
}
