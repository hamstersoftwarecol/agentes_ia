<?php

namespace App\Enums;

enum AgentLanguage: string
{
    case Spanish = 'es';
    case English = 'en';
    case Portuguese = 'pt';

    public function label(): string
    {
        return match ($this) {
            self::Spanish => 'Español',
            self::English => 'English',
            self::Portuguese => 'Português',
        };
    }

    /** Nombre del idioma tal como se le indica al modelo. */
    public function promptName(): string
    {
        return match ($this) {
            self::Spanish => 'español',
            self::English => 'inglés',
            self::Portuguese => 'portugués',
        };
    }
}
