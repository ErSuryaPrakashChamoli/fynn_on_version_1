<?php

namespace App\Models\Training;

use App\Models\User;
use Database\Factories\Training\TrainingQuizAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingQuizAttempt extends Model
{
    /** @use HasFactory<TrainingQuizAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'training_quiz_id',
        'training_enrollment_id',
        'trainee_id',
        'attempt_number',
        'score',
        'total_marks',
        'percentage',
        'passed',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'score' => 'integer',
            'total_marks' => 'integer',
            'percentage' => 'integer',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(TrainingQuiz::class, 'training_quiz_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainee_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TrainingQuizAnswer::class);
    }

    public function scopeOwnedBy(Builder $query, User|int $trainee): Builder
    {
        return $query->where('trainee_id', $trainee instanceof User ? $trainee->getKey() : $trainee);
    }
}
