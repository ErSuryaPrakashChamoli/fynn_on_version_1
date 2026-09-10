<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingQuiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingQuiz>
 */
class TrainingQuizFactory extends Factory
{
    protected $model = TrainingQuiz::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => TrainingLesson::factory(),
            'kind' => TrainingQuiz::KIND_QUIZ,
            'title' => rtrim(fake()->sentence(3), '.').' Quiz',
            'description' => fake()->sentence(10),
            'pass_percentage' => 60,
            'time_limit_minutes' => 15,
            'max_attempts' => 3,
            'status' => 'published',
        ];
    }

    /**
     * A course-level final assessment rather than a lesson quiz.
     */
    public function assessment(): static
    {
        return $this->state(fn (): array => [
            'quizzable_type' => TrainingCourse::class,
            'quizzable_id' => TrainingCourse::factory(),
            'kind' => TrainingQuiz::KIND_ASSESSMENT,
            'title' => 'Final Assessment',
            'pass_percentage' => 70,
            'time_limit_minutes' => 45,
            'max_attempts' => 2,
        ]);
    }
}
