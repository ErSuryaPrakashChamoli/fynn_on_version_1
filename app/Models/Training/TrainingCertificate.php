<?php

namespace App\Models\Training;

use App\Models\Tenant;
use App\Models\User;
use Database\Factories\Training\TrainingCertificateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingCertificate extends Model
{
    /** @use HasFactory<TrainingCertificateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'training_enrollment_id',
        'training_course_id',
        'trainee_id',
        'certificate_number',
        'final_score',
        'issued_on',
        'issued_by',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'final_score' => 'integer',
            'issued_on' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainee_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeOwnedBy(Builder $query, User|int $trainee): Builder
    {
        return $query->where('trainee_id', $trainee instanceof User ? $trainee->getKey() : $trainee);
    }
}
