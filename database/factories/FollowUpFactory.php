<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'follow_up_type' => fake()->randomElement(['Call', 'WhatsApp', 'Email', 'Visit']),
            'status' => 'Awaiting Low ROI',
            'remarks' => fake()->sentence(),
            'next_follow_up_date' => now()->addDay(),
        ];
    }

    /** Due a few minutes from now — inside the reminder window. */
    public function dueSoon(): static
    {
        return $this->state(fn (): array => ['next_follow_up_date' => now()->addMinutes(5)]);
    }

    /** Past its time already. */
    public function overdue(): static
    {
        return $this->state(fn (): array => ['next_follow_up_date' => now()->subHours(2)]);
    }
}
