<?php

namespace App\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Human = 'human'; // una persona del equipo respondió desde el panel
}
