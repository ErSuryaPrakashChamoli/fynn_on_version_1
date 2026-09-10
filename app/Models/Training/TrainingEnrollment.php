<?php

namespace App\Models\Training;

use App\Models\Tenant;
use App\Models\User;
use Database\Factories\Training\TrainingEnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The one row that links a trainee to a course.
 *
 * Everything a trainee is allowed to read hangs off this: lesson
 * progress, quiz attempts, documents, remarks, the certificate. That is
 * intentional — authorising a trainee reduces to "does an enrollment
 * with this id belong to you?", which is a single scopeOwnedBy() away
 * and is exactly what TrainingEnrollmentPolicy asks.
 */
class TrainingEnrollment extends Model
{
    /** @use HasFactory<TrainingEnrollmentFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'training_batch_id',
        'training_course_id',
        'trainee_id',
        'status',
        'progress_percentage',
        'enrolled_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percentage' => 'integer',
            'enrolled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TrainingBatch::class, 'training_batch_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainee_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(TrainingLessonProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(TrainingQuizAttempt::class);
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(TrainingRemark::class)->latest();
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(TrainingCertificate::class);
    }

    public function scopeOwnedBy(Builder $query, User|int $trainee): Builder
    {
        return $query->where('trainee_id', $trainee instanceof User ? $trainee->getKey() : $trainee);
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }
}
