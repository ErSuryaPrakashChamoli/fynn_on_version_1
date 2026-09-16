<?php

namespace Database\Factories;

use App\Models\OtherBankSupportTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtherBankSupportTarget>
 */
class OtherBankSupportTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'target_amount' => fake()->numberBetween(10, 100) * 100000,
        ];
    }
}
