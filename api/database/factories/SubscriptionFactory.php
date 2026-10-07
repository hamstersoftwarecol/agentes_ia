<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => fn () => Plan::where('slug', 'pro')->value('id'),
            'status' => SubscriptionStatus::Active,
            'source' => 'manual',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->startOfMonth()->addMonth(),
        ];
    }

    public function plan(string $slug): static
    {
        return $this->state(fn () => ['plan_id' => Plan::where('slug', $slug)->value('id')]);
    }

    public function trialing(int $daysLeft = 14): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trialing, 'source' => 'trial',
            'trial_ends_at' => now()->addDays($daysLeft), 'current_period_start' => now()->subDays(14 - $daysLeft),
            'current_period_end' => now()->addDays($daysLeft),
        ]);
    }
}
