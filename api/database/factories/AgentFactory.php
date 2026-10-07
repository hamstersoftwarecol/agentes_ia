<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'role' => 'Asesor comercial',
            'goal' => 'Ayudar a los clientes con sus preguntas.',
            'tone' => 'friendly_professional',
            'language' => 'es',
            'welcome_message' => '¡Hola! ¿En qué puedo ayudarte?',
            'is_active' => true,
        ];
    }
}
