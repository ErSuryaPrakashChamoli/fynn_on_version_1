<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingLesson>
 */
class TrainingLessonFactory extends Factory
{
    protected $model = TrainingLesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_module_id' => TrainingModule::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'summary' => fake()->sentence(12),
            'content' => fake()->paragraphs(4, true),
            'content_type' => 'text',
            'video_url' => null,
            'duration_minutes' => fake()->numberBetween(5, 45),
            'sort_order' => fake()->numberBetween(1, 10),
            'is_required' => true,
            'status' => 'published',
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'content_type' => 'video',
            // A Creative Commons sample clip, used as a stand-in so the
            // player is demonstrably working without shipping any
            // copyrighted training footage.
            'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
        ]);
    }
}
