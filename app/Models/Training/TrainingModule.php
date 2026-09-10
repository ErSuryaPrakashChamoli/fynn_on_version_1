<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingModule extends Model
{
    /** @use HasFactory<TrainingModuleFactory> */
    use HasFactory;

    protected $fillable = [
        'training_course_id',
        'title',
        'description',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(TrainingLesson::class)->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
