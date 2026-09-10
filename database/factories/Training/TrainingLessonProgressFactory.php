<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingLessonProgress>
 */
class TrainingLessonProgressFactory extends Factory
{
    protected $model = TrainingLessonProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_enrollment_id' => TrainingEnrollment::factory(),
            'training_lesson_id' => TrainingLesson::factory(),
            'status' => 'completed',
            'progress_percentage' => 100,
            'seconds_spent' => fake()->numberBetween(120, 3600),
            'started_at' => now()->subDays(fake()->numberBetween(1, 20)),
            'completed_at' => now()->subDays(fake()->numberBetween(0, 5)),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => 'in_progress',
            'progress_percentage' => fake()->numberBetween(10, 80),
            'completed_at' => null,
        ]);
    }
}
