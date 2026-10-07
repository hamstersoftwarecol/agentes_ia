<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';

    /** Puede cambiar la configuración de la empresa. */
    public function canManageOrganization(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * ¿Puede quien tiene este rol invitar, cambiar o quitar a alguien con el rol `$target`?
     * El propietario gestiona administradores y agentes; un administrador solo agentes; nadie toca al propietario.
     */
    public function canAssign(self $target): bool
    {
        return match ($this) {
            self::Owner => $target !== self::Owner,
            self::Admin => $target === self::Agent,
            self::Agent => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'propietario',
            self::Admin => 'administrador',
            self::Agent => 'agente',
        };
    }
}
