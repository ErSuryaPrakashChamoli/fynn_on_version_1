<?php

namespace Database\Factories;

use App\Models\CustomerDataField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDataField>
 */
class CustomerDataFieldFactory extends Factory
{
    protected $model = CustomerDataField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = ucwords(fake()->unique()->words(2, true));

        return [
            'key' => CustomerDataField::keyForLabel($label),
            'label' => $label,
            'type' => 'text',
            'aliases' => [],
            'sort_order' => 100,
        ];
    }
}
