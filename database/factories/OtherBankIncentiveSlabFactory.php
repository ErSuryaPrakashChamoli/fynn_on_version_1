<?php

namespace Database\Factories;

use App\Models\OtherBankIncentiveSlab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtherBankIncentiveSlab>
 */
class OtherBankIncentiveSlabFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_month' => now()->startOfMonth()->toDateString(),
            'min_achievement' => fake()->unique()->numberBetween(1, 50) * 100000,
            'payout_type' => OtherBankIncentiveSlab::PAYOUT_FIXED,
            'payout_value' => fake()->numberBetween(1, 50) * 1000,
        ];
    }

    public function percentage(float $percent): static
    {
        return $this->state(fn (array $attributes): array => [
            'payout_type' => OtherBankIncentiveSlab::PAYOUT_PERCENTAGE,
            'payout_value' => $percent,
        ]);
    }
}
