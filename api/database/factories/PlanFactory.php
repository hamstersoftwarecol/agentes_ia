<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->word(),
            'price_cents' => 2900,
            'max_agents' => 2,
            'max_monthly_messages' => 2000,
            'max_sites' => 2,
            'max_users' => 5,
            'features' => ['human_inbox' => true, 'widget_customization' => true, 'analytics' => true],
        ];
    }
}
