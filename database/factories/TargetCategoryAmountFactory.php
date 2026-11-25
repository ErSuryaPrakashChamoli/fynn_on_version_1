<?php

namespace Database\Factories;

use App\Models\TargetCategory;
use App\Models\TargetCategoryAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TargetCategoryAmount>
 */
class TargetCategoryAmountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_category_id' => TargetCategory::factory(),
            'effective_from' => now()->startOfMonth()->toDateString(),
            'amount' => fake()->numberBetween(20, 50) * 100000,
        ];
    }
}
