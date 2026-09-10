<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingLessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TrainingLesson extends Model
{
    /** @use HasFactory<TrainingLessonFactory> */
    use HasFactory;

    protected $fillable = [
        'training_module_id',
        'title',
        'summary',
        'content',
        'content_type',
        'video_url',
        'duration_minutes',
        'sort_order',
        'is_required',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(TrainingModule::class, 'training_module_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TrainingLessonDocument::class);
    }

    public function quizzes(): MorphMany
    {
        return $this->morphMany(TrainingQuiz::class, 'quizzable');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(TrainingLessonProgress::class);
    }
}
