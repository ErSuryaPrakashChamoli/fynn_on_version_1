<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingLessonDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingLessonDocument extends Model
{
    /** @use HasFactory<TrainingLessonDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'training_lesson_id',
        'title',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_downloadable',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_downloadable' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'training_lesson_id');
    }
}
