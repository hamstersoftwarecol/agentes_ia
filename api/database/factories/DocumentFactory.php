<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => DocumentType::Text,
            'title' => fake()->sentence(3),
            'content' => fake()->paragraph(),
            'status' => DocumentStatus::Pending,
        ];
    }
}
