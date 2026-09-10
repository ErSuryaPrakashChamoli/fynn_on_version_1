<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoApplication;
use App\Models\Demo\DemoCustomer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoApplication>
 */
class DemoApplicationFactory extends Factory
{
    protected $model = DemoApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $applied = fake()->numberBetween(300000, 4000000);

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'demo_customer_id' => DemoCustomer::factory(),
            'application_no' => 'APP'.fake()->unique()->numberBetween(100000, 999999),
            'lan_no' => null,
            'applied_amount' => $applied,
            'sanctioned_amount' => 0,
            'disbursed_amount' => 0,
            'interest_rate' => fake()->randomFloat(2, 9, 16),
            'tenure_months' => fake()->randomElement([12, 24, 36, 48, 60]),
            'status' => 'login',
            'applied_on' => fake()->dateTimeBetween('-5 months', 'now'),
            'sanctioned_on' => null,
            'disbursed_on' => null,
            'payout_rate' => fake()->randomFloat(2, 1.5, 4),
            'remarks' => null,
        ];
    }

    /**
     * Sanctions a portion of the applied amount, which is what makes the
     * demo funnel look like a real book rather than 100% approvals.
     */
    public function sanctioned(): static
    {
        return $this->state(function (array $attributes): array {
            $appliedOn = $attributes['applied_on'] ?? now()->subMonths(2);

            return [
                'status' => 'sanctioned',
                'sanctioned_amount' => (int) ($attributes['applied_amount'] * fake()->randomFloat(2, 0.6, 1.0)),
                'sanctioned_on' => fake()->dateTimeBetween($appliedOn, '+20 days'),
                'lan_no' => 'LAN'.fake()->unique()->numberBetween(1000000, 9999999),
            ];
        });
    }

    public function disbursed(): static
    {
        return $this->sanctioned()->state(function (array $attributes): array {
            $sanctionedOn = $attributes['sanctioned_on'] ?? now()->subMonth();

            return [
                'status' => 'disbursed',
                'disbursed_amount' => $attributes['sanctioned_amount'] ?? 0,
                'disbursed_on' => fake()->dateTimeBetween($sanctionedOn, '+15 days'),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => 'rejected',
            'remarks' => fake()->randomElement([
                'Income documents insufficient',
                'Existing obligations too high',
                'Employer not in approved category',
            ]),
        ]);
    }
}
