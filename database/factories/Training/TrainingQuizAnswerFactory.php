<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingQuizAnswer;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingQuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingQuizAnswer>
 */
class TrainingQuizAnswerFactory extends Factory
{
    protected $model = TrainingQuizAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_quiz_attempt_id' => TrainingQuizAttempt::factory(),
            'training_quiz_question_id' => TrainingQuizQuestion::factory(),
            'answer' => ['B'],
            'is_correct' => true,
            'marks_awarded' => 1,
        ];
    }
}
