<?php

namespace App\Models\Training;

use App\Models\User;
use Database\Factories\Training\TrainingRemarkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingRemark extends Model
{
    /** @use HasFactory<TrainingRemarkFactory> */
    use HasFactory;

    protected $fillable = [
        'training_enrollment_id',
        'trainer_id',
        'remark',
        'rating',
        'is_visible_to_trainee',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_visible_to_trainee' => 'boolean',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'training_enrollment_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function scopeVisibleToTrainee(Builder $query): Builder
    {
        return $query->where('is_visible_to_trainee', true);
    }
}
