<?php

namespace Database\Factories;

use App\Enums\EligibilityLogEvent;
use App\Models\Customer;
use App\Models\CustomerEligibilityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerEligibilityLog>
 */
class CustomerEligibilityLogFactory extends Factory
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
            'event' => EligibilityLogEvent::StatusChanged,
            'from_status' => 'consent_pending',
            'to_status' => 'eligible',
            'remarks' => fake()->sentence(),
            'user_id' => User::factory(),
        ];
    }
}
