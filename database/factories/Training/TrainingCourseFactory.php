<?php

namespace Database\Factories\Training;

use App\Models\Tenant;
use App\Models\Training\TrainingCourse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrainingCourse>
 */
class TrainingCourseFactory extends Factory
{
    protected $model = TrainingCourse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'tenant_id' => Tenant::factory(),
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'status' => 'published',
            'duration_minutes' => fake()->numberBetween(60, 900),
            'pass_percentage' => 60,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => 'draft']);
    }
}
