<?php

namespace App\Models;

use App\Enums\CommitmentStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer/case an employee claims as part of a day's fulfilment.
 *
 * @property CommitmentStage $stage stage the employee declared
 * @property CommitmentStage|null $lms_highest_stage highest stage the LMS says the case reached
 * @property CommitmentStage|null $outcome terminal outcome (dropped/rejected), never a ladder stage
 */
class DailyCommitmentEntry extends Model
{
    protected $fillable = [
        'daily_commitment_id',
        'customer_id',
        'customer_name',
        'reference',
        'stage',
        'lms_highest_stage',
        'outcome',
        'amount',
        'remarks',
    ];

    protected $casts = [
        'stage' => CommitmentStage::class,
        'lms_highest_stage' => CommitmentStage::class,
        'outcome' => CommitmentStage::class,
        'amount' => 'float',
    ];

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(DailyCommitment::class, 'daily_commitment_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The stage this row actually counts at: the better of what the
     * employee declared and what the LMS stage history proves. Declaring
     * a lower stage than the case really reached never costs credit, and
     * declaring a higher one than the LMS can back is not rewarded.
     */
    public function effectiveStage(): CommitmentStage
    {
        $declared = $this->stage;
        $lms = $this->lms_highest_stage;

        if ($lms === null || $lms->rank() === null) {
            return $declared;
        }

        return ($lms->rank() >= ($declared->rank() ?? 0)) ? $lms : $declared;
    }

    /**
     * Does this row count toward a commitment made at $stage?
     */
    public function countsToward(CommitmentStage $stage): bool
    {
        $floor = $stage->rank();
        $reached = $this->effectiveStage()->rank();

        return $floor !== null && $reached !== null && $reached >= $floor;
    }
}
