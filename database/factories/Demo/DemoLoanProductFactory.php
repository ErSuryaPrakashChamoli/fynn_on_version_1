<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoLoanProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoLoanProduct>
 */
class DemoLoanProductFactory extends Factory
{
    protected $model = DemoLoanProduct::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Personal Loan', 'Home Loan', 'Business Loan', 'Loan Against Property', 'Auto Loan',
        ]);

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'name' => $name,
            'code' => strtoupper(substr(str_replace(' ', '', $name), 0, 3)),
            'description' => fake()->sentence(14),
            'min_amount' => 100000,
            'max_amount' => 5000000,
            'min_tenure_months' => 12,
            'max_tenure_months' => 84,
            'interest_rate_from' => fake()->randomFloat(2, 8.5, 13.5),
            'is_active' => true,
        ];
    }
}
