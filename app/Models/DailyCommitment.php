<?php

namespace App\Models;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property CommitmentStage $commitment_stage
 * @property float $commitment_amount
 * @property int $commitment_count
 * @property CommitmentStage|null $current_stage
 * @property float $achievement_amount
 * @property int $achievement_count
 * @property CommitmentResult $result
 * @property Carbon|null $submitted_at
 * @property string|null $remarks
 * @property int|null $created_by
 * @property-read Employee $employee
 */
class DailyCommitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'commitment_stage',
        'commitment_amount',
        'commitment_count',
        'current_stage',
        'achievement_amount',
        'achievement_count',
        'result',
        'submitted_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'submitted_at' => 'datetime',
        'commitment_stage' => CommitmentStage::class,
        'current_stage' => CommitmentStage::class,
        'result' => CommitmentResult::class,
        'commitment_amount' => 'float',
        'achievement_amount' => 'float',
        'commitment_count' => 'integer',
        'achievement_count' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DailyCommitmentLog::class)->latest('created_at');
    }

    /**
     * The customer-wise fulfilment declared against this commitment.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(DailyCommitmentEntry::class);
    }

    /**
     * A morning commitment is a promise: once given it is fixed for
     * everyone, including the person who made it. Only an Admin can
     * correct one afterwards, and every correction is logged.
     *
     * This is only about the commitment itself (stage + amount/count) —
     * the end-of-day fulfilment stays editable by the owner.
     */
    public function isEditableBy(?User $user): bool
    {
        return (bool) $user?->hasRole('Admin');
    }

    /**
     * The day is closed once the employee has submitted their final
     * status, or once the date itself has passed.
     */
    public function isClosed(): bool
    {
        return $this->submitted_at !== null || $this->date->copy()->startOfDay()->isBefore(today());
    }

    public function scopeForDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        // whereDate, not whereBetween: the `date` cast writes a
        // "Y-m-d H:i:s" string, which sorts after a bare "Y-m-d" bound and
        // would silently drop the last day of the month.
        return $query
            ->whereDate('date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString());
    }

    /**
     * What was committed, in whichever unit this stage is measured in.
     */
    public function target(): float
    {
        return $this->commitment_stage->isCount()
            ? (float) $this->commitment_count
            : (float) $this->commitment_amount;
    }

    /**
     * What has actually been achieved, in the same unit as target().
     */
    public function achieved(): float
    {
        return $this->commitment_stage->isCount()
            ? (float) $this->achievement_count
            : (float) $this->achievement_amount;
    }

    public function pending(): float
    {
        return max($this->target() - $this->achieved(), 0);
    }

    public function achievementPercentage(): float
    {
        $target = $this->target();

        return $target > 0 ? round(($this->achieved() / $target) * 100, 1) : 0.0;
    }
}
