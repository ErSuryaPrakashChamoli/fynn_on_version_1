<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingQuizQuestion>
 */
class TrainingQuizQuestionFactory extends Factory
{
    protected $model = TrainingQuizQuestion::class;

    /**
     * Options are stored keyed A-D and correct_answer holds the key(s),
     * so re-ordering the option text can never silently change which
     * answer is right.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_quiz_id' => TrainingQuiz::factory(),
            'question' => rtrim(fake()->sentence(10), '.').'?',
            'type' => 'single_choice',
            'options' => [
                'A' => rtrim(fake()->sentence(4), '.'),
                'B' => rtrim(fake()->sentence(4), '.'),
                'C' => rtrim(fake()->sentence(4), '.'),
                'D' => rtrim(fake()->sentence(4), '.'),
            ],
            'correct_answer' => ['B'],
            'explanation' => fake()->sentence(12),
            'marks' => 1,
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}
