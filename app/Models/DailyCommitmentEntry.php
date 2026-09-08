<?php

namespace App\Models;

use App\Enums\CommitmentStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer/case an employee claims as part of a day's fulfilment.
 *
 * The mobile number is the case's identity: a name can be typed two ways
 * and an LMS id may be missing, but the number pins the declaration to a
 * real person. It is unique across the whole table — a customer is
 * claimed once, by one employee, on one day, and can never be counted
 * again on a later commitment.
 *
 * @property string|null $mobile_no normalised digits, unique across all declarations
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
        'mobile_no',
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

    /**
     * Reduce a typed number to the digits that identify it, so
     * "+91 98765-43210", "09876543210" and "9876543210" are recognised as
     * the same customer rather than three different ones. Indian numbers
     * are 10 digits; anything longer is trimmed to its last 10 so a
     * country code or a leading zero cannot be used to re-claim a case.
     */
    public static function normaliseMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile);

        if (blank($digits)) {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * The declaration that already owns this number, if any. $ignoreCommitmentId
     * excludes the commitment being edited, so re-saving your own day is
     * never a clash with yourself.
     */
    public static function claimFor(?string $mobile, ?int $ignoreCommitmentId = null): ?self
    {
        $mobile = self::normaliseMobile($mobile);

        if ($mobile === null) {
            return null;
        }

        return self::query()
            ->with('commitment.employee')
            ->where('mobile_no', $mobile)
            ->when($ignoreCommitmentId !== null, fn ($query) => $query->where('daily_commitment_id', '!=', $ignoreCommitmentId))
            ->first();
    }

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
