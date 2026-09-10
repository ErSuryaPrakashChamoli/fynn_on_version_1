<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoFollowUp;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoFollowUp>
 */
class DemoFollowUpFactory extends Factory
{
    protected $model = DemoFollowUp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory()->demo(),
            'demo_lead_id' => null,
            'demo_customer_id' => null,
            'demo_employee_id' => null,
            'type' => fake()->randomElement(['call', 'whatsapp', 'email', 'visit']),
            'scheduled_at' => fake()->dateTimeBetween('-15 days', '+20 days'),
            'status' => fake()->randomElement(['pending', 'completed', 'missed']),
            'outcome' => fake()->randomElement([null, 'interested', 'call back later', 'not reachable']),
            'remarks' => fake()->sentence(10),
        ];
    }
}
