<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoBank;
use App\Models\Tenant;
use App\Support\Portal\IndianFaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoBank>
 */
class DemoBankFactory extends Factory
{
    protected $model = DemoBank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bank = fake()->randomElement(IndianFaker::BANKS);
        $min = fake()->randomFloat(2, 9.5, 12.5);

        return [
            'tenant_id' => Tenant::factory()->demo(),
            'name' => $bank['name'],
            'short_name' => $bank['short'],
            'type' => $bank['type'],
            'min_interest_rate' => $min,
            'max_interest_rate' => $min + fake()->randomFloat(2, 2, 6),
            'min_salary' => fake()->randomElement([15000, 20000, 25000, 30000, 40000]),
            'payout_rate' => fake()->randomFloat(2, 1.5, 4.5),
            'is_active' => true,
        ];
    }
}
