<?php

namespace App\Enums;

enum AgentTone: string
{
    case FriendlyProfessional = 'friendly_professional';
    case Formal = 'formal';
    case Casual = 'casual';
    case Enthusiastic = 'enthusiastic';
    case Concise = 'concise';

    public function label(): string
    {
        return match ($this) {
            self::FriendlyProfessional => 'Amable y profesional',
            self::Formal => 'Formal y respetuoso',
            self::Casual => 'Cercano e informal',
            self::Enthusiastic => 'Entusiasta y motivador',
            self::Concise => 'Directo y conciso',
        };
    }

    /** Frase que se inserta en el prompt del agente. */
    public function promptText(): string
    {
        return match ($this) {
            self::FriendlyProfessional => 'amable y profesional',
            self::Formal => 'formal y respetuoso, tratando de usted',
            self::Casual => 'cercano e informal, como una conversación entre personas',
            self::Enthusiastic => 'entusiasta y motivador, sin exagerar',
            self::Concise => 'directo y conciso, con respuestas breves',
        };
    }
}
