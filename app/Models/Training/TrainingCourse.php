<?php

namespace App\Models\Training;

use App\Models\Tenant;
use App\Models\User;
use Database\Factories\Training\TrainingCourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TrainingCourse extends Model
{
    /** @use HasFactory<TrainingCourseFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'summary',
        'description',
        'thumbnail_path',
        'level',
        'status',
        'duration_minutes',
        'pass_percentage',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'pass_percentage' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TrainingModule::class)->orderBy('sort_order');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(TrainingLesson::class, TrainingModule::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(TrainingCertificate::class);
    }

    /**
     * The course-level final assessment, modelled as a quiz whose owner
     * is the course rather than a lesson. See the training_quizzes
     * migration for why assessments are not their own table.
     */
    public function assessments(): MorphMany
    {
        return $this->morphMany(TrainingQuiz::class, 'quizzable');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
