<?php

namespace App\Services;

use App\Models\DailyCommitment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The two daily deadlines for the Daily Commitment module.
 *
 *  09:50 — the morning commitment must exist. A stage and a number,
 *          nothing else; it is a promise, not a plan.
 *  18:30 — that promise must be answered. The employee declares what
 *          actually came in, and the day settles to Met / Overachieved /
 *          Partially Met / Failed.
 *
 * Both deadlines close the panel behind them: past 09:50 with no
 * commitment, or past 18:30 with the day still open, every screen but
 * My Commitment redirects there (App\Http\Middleware\EnsureDailyCommitmentIsDeclared)
 * and a non-dismissible prompt covers the page (App\Livewire\DailyCommitmentPrompt).
 * Yesterday's unanswered day blocks today just the same — a day cannot be
 * walked away from by waiting for midnight.
 *
 * This gate is entirely the Daily Commitment module's own. It reads
 * daily_commitments and nothing else, and no other module's behaviour
 * changes because of it.
 */
class DailyCommitmentGate
{
    /** Give the promise by this time. */
    public const MORNING_DEADLINE = '09:50';

    /** Answer it by this time. */
    public const EVENING_DEADLINE = '18:30';

    public const REASON_COMMIT = 'commit';

    public const REASON_DECLARE = 'declare';

    /**
     * Designations that owe a daily commitment. The people who carry a
     * number: the same three that carry a monthly commitment target.
     *
     * @var array<int, int>
     */
    public const REQUIRES_COMMITMENT = MonthlyTargetGate::REQUIRES_TARGET;

    /**
     * How many days back an unanswered day is still chased. Beyond this
     * the day is left to the Admin rather than locking somebody out of
     * the panel indefinitely over a week-old declaration.
     */
    public const BACKLOG_DAYS = 7;

    /**
     * Per-request memo — the middleware and the prompt both ask the same
     * question on every panel request.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $statuses = [];

    public function __construct(private MonthlyTargetGate $monthlyTargets) {}

    /*
    |--------------------------------------------------------------------------
    | Deadlines
    |--------------------------------------------------------------------------
    */

    public function morningDeadline(?Carbon $date = null): Carbon
    {
        return ($date ? $date->copy() : today())->setTimeFromTimeString(self::MORNING_DEADLINE);
    }

    public function eveningDeadline(?Carbon $date = null): Carbon
    {
        return ($date ? $date->copy() : today())->setTimeFromTimeString(self::EVENING_DEADLINE);
    }

    public function morningDeadlinePassed(?Carbon $now = null): bool
    {
        $now ??= now();

        return $now->greaterThanOrEqualTo($this->morningDeadline($now->copy()->startOfDay()));
    }

    public function eveningDeadlinePassed(?Carbon $now = null): bool
    {
        $now ??= now();

        return $now->greaterThanOrEqualTo($this->eveningDeadline($now->copy()->startOfDay()));
    }

    /*
    |--------------------------------------------------------------------------
    | Who is on the hook
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this user owes a daily commitment at all. An account with no
     * employee profile, an exited employee, or anyone off the three
     * number-carrying designations is never blocked.
     */
    public function requiresCommitment(User $user): bool
    {
        $employee = $user->employee;

        if (! $employee) {
            return false;
        }

        if (strtolower((string) $employee->exit_status) === 'yes') {
            return false;
        }

        return in_array((int) $employee->designation, self::REQUIRES_COMMITMENT, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    /**
     * What, if anything, this user is being held on right now.
     *
     * `date` is the day being chased: today once its own deadline has
     * passed, otherwise the most recent earlier day left unanswered.
     *
     * @return array{blocked: bool, reason: ?string, date: ?Carbon, commitment: ?DailyCommitment, overdue: bool}
     */
    public function status(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $key = $user->getKey().'|'.$now->format('Y-m-d H:i');

        if (isset($this->statuses[$key])) {
            return $this->statuses[$key];
        }

        return $this->statuses[$key] = $this->resolveStatus($user, $now);
    }

    /**
     * @return array{blocked: bool, reason: ?string, date: ?Carbon, commitment: ?DailyCommitment, overdue: bool}
     */
    private function resolveStatus(User $user, Carbon $now): array
    {
        $clear = [
            'blocked' => false,
            'reason' => null,
            'date' => null,
            'commitment' => null,
            'overdue' => false,
        ];

        if (! $this->requiresCommitment($user)) {
            return $clear;
        }

        // The month's targets are the outer gate, and it sends blocked
        // users somewhere this one does not allow. Two blocks in force at
        // once would bounce the user between their landing pages forever,
        // so the daily deadlines stand down until the month is open.
        if ($this->monthlyTargets->isBlocked($user)) {
            return $clear;
        }

        $employeeId = (int) $user->employee->id;
        $today = $now->copy()->startOfDay();

        // An unanswered earlier day comes first: it is already overdue,
        // and today's promise means little while yesterday's is unsettled.
        $overdue = $this->oldestUnansweredDay($employeeId, $today);

        if ($overdue !== null) {
            return [
                'blocked' => true,
                'reason' => self::REASON_DECLARE,
                'date' => $overdue->date->copy()->startOfDay(),
                'commitment' => $overdue,
                'overdue' => true,
            ];
        }

        $commitment = $this->commitmentFor($employeeId, $today);

        if ($commitment === null) {
            return $this->morningDeadlinePassed($now)
                ? [
                    'blocked' => true,
                    'reason' => self::REASON_COMMIT,
                    'date' => $today,
                    'commitment' => null,
                    'overdue' => false,
                ]
                : $clear;
        }

        if ($commitment->submitted_at === null && $this->eveningDeadlinePassed($now)) {
            return [
                'blocked' => true,
                'reason' => self::REASON_DECLARE,
                'date' => $today,
                'commitment' => $commitment,
                'overdue' => false,
            ];
        }

        return $clear;
    }

    public function isBlocked(User $user): bool
    {
        return $this->status($user)['blocked'];
    }

    /** Drop the per-request memo after a commitment or declaration lands. */
    public function forget(): void
    {
        $this->statuses = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function commitmentFor(int $employeeId, Carbon $date): ?DailyCommitment
    {
        return DailyCommitment::query()
            ->where('employee_id', $employeeId)
            ->forDate($date)
            ->first();
    }

    /**
     * The earliest day inside the backlog window that was committed to and
     * never answered. A day with no commitment at all is not chased
     * backwards — that morning is gone, and the Admin owns it.
     */
    private function oldestUnansweredDay(int $employeeId, Carbon $today): ?DailyCommitment
    {
        return DailyCommitment::query()
            ->where('employee_id', $employeeId)
            ->whereNull('submitted_at')
            ->whereDate('date', '<', $today->toDateString())
            ->whereDate('date', '>=', $today->copy()->subDays(self::BACKLOG_DAYS)->toDateString())
            ->orderBy('date')
            ->first();
    }

    /**
     * A human sentence for whichever deadline is biting — used by the
     * prompt, the middleware's flash and the My Commitment banner.
     */
    public function message(array $status): string
    {
        if ($status['reason'] === self::REASON_COMMIT) {
            return 'Your commitment for today was due by '.self::MORNING_DEADLINE.'. Give it to carry on.';
        }

        if ($status['reason'] === self::REASON_DECLARE) {
            $date = $status['date']?->format('d M Y');

            return $status['overdue']
                ? "Your commitment for {$date} was never closed. Declare what you achieved to carry on."
                : 'Your achievement against today\'s commitment was due by '.self::EVENING_DEADLINE.'. Declare it to carry on.';
        }

        return '';
    }

    /**
     * Designation label for the blocked user, used in the prompt copy.
     */
    public function designationLabel(User $user): ?string
    {
        $designation = $user->employee?->designation;

        return $designation === null
            ? null
            : (Employee::designationOptions()[$designation] ?? null);
    }
}
