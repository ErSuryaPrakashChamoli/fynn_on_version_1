<?php

namespace Database\Factories\Training;

use App\Models\Tenant;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingEnrollment>
 */
class TrainingEnrollmentFactory extends Factory
{
    protected $model = TrainingEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'training_batch_id' => null,
            'training_course_id' => TrainingCourse::factory(),
            'trainee_id' => User::factory(),
            'status' => 'in_progress',
            'progress_percentage' => fake()->numberBetween(10, 95),
            'enrolled_at' => now()->subDays(fake()->numberBetween(1, 40)),
            'started_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);
    }

    public function assigned(): static
    {
        return $this->state(fn (): array => [
            'status' => 'assigned',
            'progress_percentage' => 0,
            'started_at' => null,
        ]);
    }
}
