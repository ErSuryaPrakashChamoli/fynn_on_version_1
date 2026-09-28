<?php

namespace Database\Factories;

use App\Models\TargetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TargetCategory>
 */
class TargetCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'target_amount' => fake()->numberBetween(20, 50) * 100000,
        ];
    }
}
