<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingQuizAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingQuizAnswer extends Model
{
    /** @use HasFactory<TrainingQuizAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'training_quiz_attempt_id',
        'training_quiz_question_id',
        'answer',
        'is_correct',
        'marks_awarded',
    ];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'is_correct' => 'boolean',
            'marks_awarded' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TrainingQuizAttempt::class, 'training_quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(TrainingQuizQuestion::class, 'training_quiz_question_id');
    }
}
