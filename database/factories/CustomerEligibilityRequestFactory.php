<?php

namespace Database\Factories;

use App\Enums\EligibilityRequestStatus;
use App\Models\Customer;
use App\Models\CustomerEligibilityRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerEligibilityRequest>
 */
class CustomerEligibilityRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->state([
                'eligibility_status' => 'not_eligible',
                'eligibility_reason' => 'low_salary',
                'journey_status' => 'not_started',
            ]),
            'requested_by' => User::factory(),
            'reason' => fake()->sentence(),
            'status' => EligibilityRequestStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => EligibilityRequestStatus::Approved,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => EligibilityRequestStatus::Rejected,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_note' => fake()->sentence(),
        ]);
    }
}
