<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingModule>
 */
class TrainingModuleFactory extends Factory
{
    protected $model = TrainingModule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_course_id' => TrainingCourse::factory(),
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => fake()->sentence(14),
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => 'published',
        ];
    }
}
