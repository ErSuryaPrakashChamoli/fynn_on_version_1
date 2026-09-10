<?php

namespace Database\Factories\Training;

use App\Models\Tenant;
use App\Models\Training\TrainingBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingBatch>
 */
class TrainingBatchFactory extends Factory
{
    protected $model = TrainingBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 months', '+1 month');

        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'New Joiner Batch #'.fake()->unique()->numberBetween(1, 999),
            'code' => 'BATCH-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(12),
            'trainer_id' => User::factory(),
            'starts_on' => $start,
            'ends_on' => (clone $start)->modify('+30 days'),
            'status' => 'running',
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn (): array => ['status' => 'upcoming']);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => 'completed']);
    }
}
