<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingLessonDocument>
 */
class TrainingLessonDocumentFactory extends Factory
{
    protected $model = TrainingLessonDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->slug(3).'.pdf';

        return [
            'training_lesson_id' => TrainingLesson::factory(),
            'title' => rtrim(fake()->sentence(3), '.'),
            // 'local' is the private disk — never 'public'. See the
            // training_lesson_documents migration.
            'disk' => 'local',
            'path' => 'academy/documents/'.$name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(20_000, 2_000_000),
            'is_downloadable' => true,
        ];
    }
}
