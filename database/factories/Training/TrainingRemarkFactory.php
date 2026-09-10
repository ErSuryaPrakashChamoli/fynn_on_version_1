<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingRemark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRemark>
 */
class TrainingRemarkFactory extends Factory
{
    protected $model = TrainingRemark::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_enrollment_id' => TrainingEnrollment::factory(),
            'trainer_id' => User::factory(),
            'remark' => fake()->sentence(16),
            'rating' => fake()->numberBetween(3, 5),
            'is_visible_to_trainee' => true,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['is_visible_to_trainee' => false]);
    }
}
