<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSession>
 */
class TrainingSessionFactory extends Factory
{
    protected $model = TrainingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_batch_id' => TrainingBatch::factory(),
            'training_module_id' => null,
            'title' => rtrim(fake()->sentence(3), '.'),
            'agenda' => fake()->sentence(14),
            'scheduled_at' => fake()->dateTimeBetween('-10 days', '+20 days'),
            'duration_minutes' => fake()->randomElement([45, 60, 90, 120]),
            'mode' => fake()->randomElement(['classroom', 'online']),
            'location' => fake()->randomElement(['Training Room 1', 'Training Room 2', 'Online']),
            'status' => 'scheduled',
        ];
    }
}
