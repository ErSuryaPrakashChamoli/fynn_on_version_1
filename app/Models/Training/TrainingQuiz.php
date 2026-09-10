<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingQuizFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A quiz (owned by a lesson) or a final assessment (owned by a course).
 * `kind` is the only difference in behaviour; the question, attempt and
 * grading machinery is shared.
 */
class TrainingQuiz extends Model
{
    /** @use HasFactory<TrainingQuizFactory> */
    use HasFactory;

    public const KIND_QUIZ = 'quiz';

    public const KIND_ASSESSMENT = 'assessment';

    protected $fillable = [
        'quizzable_type',
        'quizzable_id',
        'kind',
        'title',
        'description',
        'pass_percentage',
        'time_limit_minutes',
        'max_attempts',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pass_percentage' => 'integer',
            'time_limit_minutes' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function quizzable(): MorphTo
    {
        return $this->morphTo();
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TrainingQuizQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TrainingQuizAttempt::class);
    }

    public function scopeAssessments(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_ASSESSMENT);
    }

    public function scopeQuizzes(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_QUIZ);
    }

    public function isAssessment(): bool
    {
        return $this->kind === self::KIND_ASSESSMENT;
    }

    public function totalMarks(): int
    {
        return (int) $this->questions()->sum('marks');
    }

    /**
     * The course this quiz ultimately belongs to, whichever end of the
     * polymorphic relation it hangs from. Used by the attempt guard to
     * resolve the trainee's enrollment.
     */
    public function resolveCourse(): ?TrainingCourse
    {
        $owner = $this->quizzable;

        return match (true) {
            $owner instanceof TrainingCourse => $owner,
            $owner instanceof TrainingLesson => $owner->module?->course,
            default => null,
        };
    }
}
