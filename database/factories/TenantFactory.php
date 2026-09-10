<?php

namespace Database\Factories;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => TenantType::Client,
            'is_demo' => false,
            'is_active' => true,
            'brand_name' => null,
            'settings' => null,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (): array => [
            'name' => 'FynnEdge Advisory',
            'slug' => Tenant::PRODUCTION_SLUG,
            'type' => TenantType::Production,
            'is_demo' => false,
            'brand_name' => 'FynnEdge',
        ]);
    }

    public function demo(): static
    {
        return $this->state(fn (): array => [
            'name' => 'FYNN-ON Demo',
            'slug' => Tenant::DEMO_SLUG,
            'type' => TenantType::Demo,
            'is_demo' => true,
            'brand_name' => 'FYNN-ON',
        ]);
    }
}
