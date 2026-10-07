<?php

namespace Database\Factories;

use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeBase> */
class KnowledgeBaseFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'description' => null];
    }
}
