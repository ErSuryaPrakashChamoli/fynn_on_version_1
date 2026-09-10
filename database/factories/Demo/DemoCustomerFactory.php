<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoCustomer;
use App\Models\Tenant;
use App\Support\Portal\IndianFaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoCustomer>
 */
class DemoCustomerFactory extends Factory
{
    protected $model = DemoCustomer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = IndianFaker::name();
        $salary = fake()->numberBetween(30000, 300000);

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'demo_lead_id' => null,
            'demo_employee_id' => null,
            'customer_code' => 'CU'.fake()->unique()->numberBetween(100000, 999999),
            'customer_name' => $name,
            'mobile_no' => IndianFaker::mobile(),
            'email' => IndianFaker::email($name),
            'pan_number' => IndianFaker::pan(),
            'city' => IndianFaker::city(),
            'company_name' => fake()->randomElement(IndianFaker::COMPANIES),
            'company_category' => fake()->randomElement(IndianFaker::COMPANY_CATEGORIES),
            'salary' => $salary,
            'eligible_loan_amount' => $salary * fake()->numberBetween(10, 24),
            'journey_status' => fake()->randomElement(array_keys(DemoCustomer::JOURNEY_STATUSES)),
            'eligibility_status' => fake()->randomElement(['eligible', 'pending', 'not_eligible']),
        ];
    }
}
