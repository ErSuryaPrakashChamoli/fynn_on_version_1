<?php

namespace Database\Factories;

use App\Enums\OtherBankRemarkStage;
use App\Models\Customer;
use App\Models\OtherBankSupportRemark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtherBankSupportRemark>
 */
class OtherBankSupportRemarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'stage' => fake()->randomElement(OtherBankRemarkStage::cases()),
            'remark' => fake()->sentence(),
        ];
    }
}
