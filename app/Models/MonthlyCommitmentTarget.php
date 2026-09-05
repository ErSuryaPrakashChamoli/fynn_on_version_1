<?php

namespace App\Models;

use App\Enums\CommitmentStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly target used ONLY by the Daily Commitment module. It never reads
 * from or writes to the existing LMS target (employees.category /
 * employee_targets, consumed by AchievementCalculatorService).
 *
 * @property int $employee_id
 * @property Carbon $month
 * @property CommitmentStage $stage
 */
class MonthlyCommitmentTarget extends Model
{
    protected $fillable = [
        'employee_id',
        'month',
        'stage',
        'target_amount',
        'target_count',
    ];

    protected $casts = [
        'month' => 'date',
        'stage' => CommitmentStage::class,
        'target_amount' => 'float',
        'target_count' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('month', $month->copy()->startOfMonth()->toDateString());
    }

    public function target(): float
    {
        return $this->stage->isCount()
            ? (float) $this->target_count
            : (float) $this->target_amount;
    }
}
