<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingQuizAttempt>
 */
class TrainingQuizAttemptFactory extends Factory
{
    protected $model = TrainingQuizAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = 10;
        $score = fake()->numberBetween(4, 10);
        $percentage = (int) round(($score / $total) * 100);

        return [
            'training_quiz_id' => TrainingQuiz::factory(),
            'training_enrollment_id' => TrainingEnrollment::factory(),
            'trainee_id' => User::factory(),
            'attempt_number' => 1,
            'score' => $score,
            'total_marks' => $total,
            'percentage' => $percentage,
            'passed' => $percentage >= 60,
            'status' => 'completed',
            'started_at' => now()->subDays(fake()->numberBetween(1, 10)),
            'completed_at' => now()->subDays(fake()->numberBetween(0, 1)),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => 'in_progress',
            'score' => 0,
            'percentage' => 0,
            'passed' => false,
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'score' => 4,
            'percentage' => 40,
            'passed' => false,
        ]);
    }
}
