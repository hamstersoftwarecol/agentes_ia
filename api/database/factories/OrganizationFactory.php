<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    /** Por defecto, una cortesía activa del plan Pro (topes amplios). Los tests de facturación la cambian. */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            if (! $organization->subscription()->exists()) {
                Subscription::factory()->create(['organization_id' => $organization->id]);
            }
        });
    }

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'timezone' => 'America/Bogota',
            'business_hours' => Organization::defaultBusinessHours(),
        ];
    }
}
