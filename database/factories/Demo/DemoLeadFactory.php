<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoLead;
use App\Models\Tenant;
use App\Support\Portal\IndianFaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoLead>
 */
class DemoLeadFactory extends Factory
{
    protected $model = DemoLead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = IndianFaker::name();
        $salary = fake()->numberBetween(25000, 250000);

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'lead_code' => 'LD'.fake()->unique()->numberBetween(100000, 999999),
            'customer_name' => $name,
            'mobile_no' => IndianFaker::mobile(),
            'email' => IndianFaker::email($name),
            'pan_number' => IndianFaker::pan(),
            'city' => IndianFaker::city(),
            'source' => fake()->randomElement(IndianFaker::LEAD_SOURCES),
            'salary' => $salary,
            'requested_amount' => $salary * fake()->numberBetween(8, 20),
            'status' => fake()->randomElement(array_keys(DemoLead::STATUSES)),
            'follow_up_date' => fake()->dateTimeBetween('-10 days', '+15 days'),
            'remarks' => fake()->sentence(10),
            'is_converted' => false,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn (): array => [
            'status' => 'converted',
            'is_converted' => true,
        ]);
    }
}
