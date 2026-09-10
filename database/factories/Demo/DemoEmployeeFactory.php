<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoEmployee;
use App\Models\Tenant;
use App\Support\Portal\IndianFaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoEmployee>
 */
class DemoEmployeeFactory extends Factory
{
    protected $model = DemoEmployee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = IndianFaker::name();

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'emp_code' => 'FE'.fake()->unique()->numberBetween(10000, 99999),
            'name' => $name,
            'email' => IndianFaker::email($name),
            'mobile_no' => IndianFaker::mobile(),
            'designation' => fake()->randomElement([
                'Sales Executive', 'Senior Sales Executive', 'Team Leader', 'Manager',
            ]),
            'department' => fake()->randomElement(['Sales', 'Credit', 'Operations']),
            'city' => IndianFaker::city(),
            'reports_to' => null,
            'joined_on' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'monthly_target' => fake()->randomElement([2000000, 3000000, 5000000, 8000000]),
            'is_active' => true,
        ];
    }

    public function designation(string $designation): static
    {
        return $this->state(fn (): array => ['designation' => $designation]);
    }
}
