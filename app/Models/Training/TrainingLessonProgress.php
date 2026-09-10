<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingLessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingLessonProgress extends Model
{
    /** @use HasFactory<TrainingLessonProgressFactory> */
    use HasFactory;

    protected $table = 'training_lesson_progress';

    protected $fillable = [
        'training_enrollment_id',
        'training_lesson_id',
        'status',
        'progress_percentage',
        'seconds_spent',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percentage' => 'integer',
            'seconds_spent' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'training_lesson_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
