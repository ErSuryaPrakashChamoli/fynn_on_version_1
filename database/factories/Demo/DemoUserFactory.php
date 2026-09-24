<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoUser>
 */
class DemoUserFactory extends Factory
{
    protected $model = DemoUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Password@123',
            'is_active' => true,
            'expires_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
