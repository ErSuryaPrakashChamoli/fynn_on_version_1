<?php

namespace App\Services;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\DailyCallerOtp;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\DailyCommitmentLog;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use App\Support\Performance\PerformancePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every calculation the Daily Commitment module needs, in one place.
 *
 * Nothing here writes to the existing LMS journey, targets, incentives or
 * attendance — it only reads `customers`, `customer_stage_histories` and
 * `user_login_sessions`, exactly as the existing Performance module does.
 *
 * Two rules matter:
 *
 * 1. ACHIEVEMENT IS DECLARED, NOT INFERRED. A day's achievement is the
 *    customer-wise fulfilment the employee submits against that day's
 *    commitment (daily_commitment_entries). Old cases that merely sit at
 *    Approved in the LMS can never drift into today's number, because
 *    nothing counts until the employee names the case.
 *
 * 2. HIGHEST STAGE REACHED. A declared row counts at the better of what
 *    the employee entered and what the LMS stage history proves the case
 *    reached — so Approved -> Rejected still counts as Approved. Current
 *    journey_status is never used on its own.
 *
 * "Current pipeline" (everything the employee currently has in flight) is
 * a separate, undated figure and is deliberately never mixed into a day's
 * achievement.
 */
class DailyCommitmentService
{
    /**
     * The `customer_stage_histories.status_value` written by
     * Customer::booted() for each ladder transition.
     */
    private const HISTORY_STATUS = [
        'sfl' => 'Moved to Sfl',
        'underwriting' => 'Moved to Underwriting',
        'approved' => 'Moved to Approved',
        'disbursed' => 'Moved to Sanctioned',
    ];

    /**
     * The period presets the dashboard and reports filter by. Deliberately
     * this module's own list — the LMS-wide month selector (SelectedMonth)
     * and PerformancePeriod belong to the Performance module and are not
     * reused here, so changing one can never move the other.
     *
     * @return array<string, string>
     */
    public static function rangeOptions(): array
    {
        return [
            'today' => 'Today',
            'last_week' => 'Last 7 days',
            'this_month' => 'This month (till date)',
            'last_month' => 'Last month',
            'last_3_months' => 'Last 3 months',
            'half_yearly' => 'Half yearly (last 6 months)',
            'yearly' => 'Yearly (last 12 months)',
            'custom' => 'Custom date range',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolveRange(?string $type, ?string $from = null, ?string $to = null): array
    {
        $today = today();

        return match ($type) {
            'last_week' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            'this_month' => [$today->copy()->startOfMonth()->startOfDay(), $today->copy()->endOfDay()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'last_3_months' => [$today->copy()->subMonthsNoOverflow(3)->startOfDay(), $today->copy()->endOfDay()],
            'half_yearly' => [$today->copy()->subMonthsNoOverflow(6)->startOfDay(), $today->copy()->endOfDay()],
            'yearly' => [$today->copy()->subMonthsNoOverflow(12)->startOfDay(), $today->copy()->endOfDay()],
            'custom' => [
                ($from ? Carbon::parse($from) : $today)->copy()->startOfDay(),
                ($to ? Carbon::parse($to) : ($from ? Carbon::parse($from) : $today))->copy()->endOfDay(),
            ],
            default => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
        };
    }

    /**
     * Employees this user may see: themselves plus their whole reporting
     * tree (Admin sees everyone). Reuses the app's single hierarchy engine
     * rather than restating the rules.
     */
    public function visibleEmployeeIds(User $user): Collection
    {
        if ($user->hasRole('Admin')) {
            return Employee::query()->pluck('id');
        }

        $employee = $user->employee;

        return $employee
            ? HierarchyHelper::subordinateIds($employee)->unique()->values()
            : collect();
    }

    public function canView(User $user, int $employeeId): bool
    {
        return $this->visibleEmployeeIds($user)->contains($employeeId);
    }

    /*
    |--------------------------------------------------------------------------
    | Stage resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The highest ladder stage each customer ever reached, the terminal
     * outcome if any, and the amount the case carries at that stage.
     *
     * Deliberately undated: this answers "how far did this case get?", not
     * "when". The day a case belongs to is decided by the employee naming
     * it on that day's fulfilment.
     *
     * Stage mapping to the existing LMS (verified against the data):
     *  - Docs Received: customers.documentation_status = 'complete' — the
     *    documentation checklist inside "Step 1: SFL (Source File
     *    Logging)". NOT customer_documents, which only ever holds the
     *    post-disbursal "Disbursal Letter", and NOT documents_submitted,
     *    which is also post-disbursal.
     *  - SFL: eligibility_status = 'eligible'. CreateCustomer sets
     *    journey_status to 'sfl' exactly when the case is eligible (and to
     *    'not_started' otherwise), and no 'Moved to Sfl' history row is
     *    ever written, so eligibility IS the SFL event.
     *  - Underwriting / Approved / Disbursed: the stage-history rows, with
     *    the journey/amount columns as a fallback for older data.
     *
     * disbursal_finalized is NOT used to detect Disbursed:
     * CustomerJourneyService::sanction() sets it true for dropped cases too.
     *
     * @param  Collection<int, int>  $customerIds
     * @return array<int, array{stage: ?CommitmentStage, outcome: ?CommitmentStage, amount: float}>
     */
    public function highestStageFor(Collection $customerIds): array
    {
        $customerIds = $customerIds->filter()->unique()->values();

        if ($customerIds->isEmpty()) {
            return [];
        }

        $reached = CustomerStageHistory::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status_value', array_values(self::HISTORY_STATUS))
            ->get(['customer_id', 'status_value'])
            ->groupBy('customer_id')
            ->map(fn (Collection $rows): array => $rows->pluck('status_value')->unique()->all())
            ->all();

        $customers = Customer::query()
            ->whereIn('id', $customerIds)
            ->get([
                'id', 'journey_status', 'underwriting_status', 'disbursal_status',
                'eligibility_status', 'documentation_status', 'approval_date',
                'eligible_loan_amount', 'approved_loan_amount', 'sanctioned_loan_amount',
            ]);

        $resolved = [];

        foreach ($customers as $customer) {
            $history = $reached[$customer->id] ?? [];
            $status = (string) $customer->journey_status;

            $stage = match (true) {
                in_array(self::HISTORY_STATUS['disbursed'], $history, true)
                    || $status === 'sanctioned'
                    || $customer->disbursal_status === 'disbursed' => CommitmentStage::Disbursed,

                in_array(self::HISTORY_STATUS['approved'], $history, true)
                    || in_array($status, ['approved', 'carry_forward', 'dropped'], true)
                    || filled($customer->approved_loan_amount)
                    || filled($customer->approval_date) => CommitmentStage::Approved,

                in_array(self::HISTORY_STATUS['underwriting'], $history, true)
                    || in_array($status, ['underwriting', 'not_approved'], true)
                    || filled($customer->underwriting_status) => CommitmentStage::Underwriting,

                in_array(self::HISTORY_STATUS['sfl'], $history, true)
                    || $customer->eligibility_status === 'eligible' => CommitmentStage::Sfl,

                $customer->documentation_status === 'complete' => CommitmentStage::DocsReceived,

                default => null,
            };

            $outcome = match (true) {
                $status === 'not_approved' => CommitmentStage::Rejected,
                $status === 'dropped' || $customer->disbursal_status === 'dropped' => CommitmentStage::Dropped,
                default => null,
            };

            $resolved[$customer->id] = [
                'stage' => $stage,
                'outcome' => $outcome,
                'amount' => $this->amountAtStage($customer, $stage),
            ];
        }

        return $resolved;
    }

    /**
     * Current pipeline, built ONLY from this module's own data: the cases
     * employees declared on their commitments in the period, that have not
     * closed out. Nothing here reads the LMS customer book — the pipeline
     * moves only when a commitment is made and its fulfilment updated.
     *
     * A declared case leaves the pipeline when it is Disbursed (done) or
     * carries a Dropped/Rejected outcome (dead). Everything else is still
     * in flight at whatever stage it was last declared to have reached.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array{stages: array<string, array{amount: float, count: int}>, total_amount: float, total_count: int}>
     */
    public function pipeline(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $employeeIds = $employeeIds->unique()->values();

        $blank = fn (): array => [
            'stages' => collect(CommitmentStage::ladder())
                ->mapWithKeys(fn (CommitmentStage $s): array => [$s->value => ['amount' => 0.0, 'count' => 0]])
                ->all(),
            'total_amount' => 0.0,
            'total_count' => 0,
        ];

        $pipeline = $employeeIds->mapWithKeys(fn (int $id): array => [$id => $blank()])->all();

        if ($employeeIds->isEmpty()) {
            return $pipeline;
        }

        $entries = DailyCommitmentEntry::query()
            ->join('daily_commitments', 'daily_commitments.id', '=', 'daily_commitment_entries.daily_commitment_id')
            ->whereIn('daily_commitments.employee_id', $employeeIds)
            ->whereDate('daily_commitments.date', '>=', $start->toDateString())
            ->whereDate('daily_commitments.date', '<=', $end->toDateString())
            ->whereNull('daily_commitment_entries.outcome')
            ->select([
                'daily_commitment_entries.*',
                'daily_commitments.employee_id as owner_employee_id',
            ])
            ->get();

        foreach ($entries as $entry) {
            $stage = $entry->effectiveStage();

            if ($stage === CommitmentStage::Disbursed) {
                continue;
            }

            $employeeId = (int) $entry->owner_employee_id;

            if (! isset($pipeline[$employeeId]['stages'][$stage->value])) {
                continue;
            }

            $pipeline[$employeeId]['stages'][$stage->value]['amount'] += (float) $entry->amount;
            $pipeline[$employeeId]['stages'][$stage->value]['count']++;
            $pipeline[$employeeId]['total_amount'] += (float) $entry->amount;
            $pipeline[$employeeId]['total_count']++;
        }

        return $pipeline;
    }

    /*
    |--------------------------------------------------------------------------
    | Achievement
    |--------------------------------------------------------------------------
    */

    /**
     * Achievement from a commitment's declared fulfilment rows: the sum of
     * every row whose effective stage is at or beyond the committed stage.
     *
     * @param  Collection<int, DailyCommitmentEntry>  $entries
     * @return array{amount: float, count: int, counting: Collection<int, DailyCommitmentEntry>}
     */
    public function achievementFromEntries(Collection $entries, CommitmentStage $stage): array
    {
        $counting = $entries->filter(fn (DailyCommitmentEntry $entry): bool => $entry->countsToward($stage));

        return [
            'amount' => (float) $counting->sum('amount'),
            'count' => $counting->count(),
            'counting' => $counting->values(),
        ];
    }

    /**
     * Recompute one commitment's achievement from its declared fulfilment,
     * persist the snapshot, and log the movement if anything changed.
     */
    public function syncCommitment(DailyCommitment $commitment): DailyCommitment
    {
        $stage = $commitment->commitment_stage;
        $entries = $commitment->entries()->get();

        if ($stage->isCount()) {
            // An OTP commitment is measured by cases opened that day, which
            // already carries its own date boundary (customers.created_at).
            $achievedCount = $this->otpCounts(
                collect([$commitment->employee_id]),
                $commitment->date->copy()->startOfDay(),
                $commitment->date->copy()->endOfDay(),
            )[$commitment->employee_id] ?? 0;

            $achievedAmount = 0.0;
            $highest = CommitmentStage::Otp;
        } else {
            $achievement = $this->achievementFromEntries($entries, $stage);
            $achievedAmount = $achievement['amount'];
            $achievedCount = $achievement['count'];
            $highest = $this->highestDeclaredStage($entries);
        }

        $target = $commitment->target();
        $achieved = $stage->isCount() ? (float) $achievedCount : $achievedAmount;

        $result = CommitmentResult::decide($target, $achieved, dayClosed: $commitment->isClosed());

        $changed = round((float) $commitment->achievement_amount, 2) !== round($achievedAmount, 2)
            || (int) $commitment->achievement_count !== (int) $achievedCount
            || $commitment->result !== $result;

        if ($changed) {
            DailyCommitmentLog::create([
                'daily_commitment_id' => $commitment->id,
                'employee_id' => $commitment->employee_id,
                'old_stage' => $commitment->current_stage?->value,
                'new_stage' => $highest?->value,
                'old_amount' => $commitment->achievement_amount,
                'new_amount' => $achievedAmount,
                'old_count' => $commitment->achievement_count,
                'new_count' => $achievedCount,
                'change_type' => 'progress',
                'note' => 'Achievement recalculated from the declared fulfilment.',
            ]);
        }

        $commitment->forceFill([
            'current_stage' => $highest?->value,
            'achievement_amount' => $achievedAmount,
            'achievement_count' => $achievedCount,
            'result' => $result,
        ])->save();

        return $commitment;
    }

    /**
     * Per-stage split of a commitment's declared fulfilment — "where the
     * business actually sits" for that day.
     *
     * @param  Collection<int, DailyCommitmentEntry>  $entries
     * @return array{stages: array<string, array{amount: float, count: int}>, dropped: int, rejected: int}
     */
    public function entryBreakdown(Collection $entries): array
    {
        $breakdown = [
            'stages' => collect(CommitmentStage::ladder())
                ->mapWithKeys(fn (CommitmentStage $s): array => [$s->value => ['amount' => 0.0, 'count' => 0]])
                ->all(),
            'dropped' => 0,
            'rejected' => 0,
        ];

        foreach ($entries as $entry) {
            $stage = $entry->effectiveStage();

            if (isset($breakdown['stages'][$stage->value])) {
                $breakdown['stages'][$stage->value]['amount'] += (float) $entry->amount;
                $breakdown['stages'][$stage->value]['count']++;
            }

            if ($entry->outcome === CommitmentStage::Dropped) {
                $breakdown['dropped']++;
            }

            if ($entry->outcome === CommitmentStage::Rejected) {
                $breakdown['rejected']++;
            }
        }

        return $breakdown;
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance & OTP (existing LMS data, unchanged definitions)
    |--------------------------------------------------------------------------
    */

    /**
     * Attendance for this module, taken only from the existing Screen Time
     * / login sessions: if a person has ANY login row on a day, they are
     * Present for that day. No screen-time threshold, no second attendance
     * system, nothing entered by hand.
     *
     * @return array<int, int> distinct days each employee logged in
     */
    public function presentDays(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $days = UserLoginSession::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('login_at', [$start, $end])
            ->get(['employee_id', 'login_at'])
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): int => $rows
                ->pluck('login_at')
                ->map(fn ($at): string => Carbon::parse($at)->toDateString())
                ->unique()
                ->count());

        return $employeeIds
            ->mapWithKeys(fn (int $id): array => [$id => (int) ($days[$id] ?? 0)])
            ->all();
    }

    /**
     * Present/absent on a single date.
     *
     * @return array<int, bool>
     */
    public function presence(Collection $employeeIds, Carbon $date): array
    {
        return collect($this->presentDays($employeeIds, $date->copy()->startOfDay(), $date->copy()->endOfDay()))
            ->map(fn (int $days): bool => $days > 0)
            ->all();
    }

    /**
     * Actual OTPs = cases opened in the period, credited to the case
     * owner. This is the LMS's existing otp_count definition (see
     * EmployeePerformanceMetricsService), not a new one.
     *
     * @return array<int, int>
     */
    public function otpCounts(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $counts = Customer::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        return $employeeIds
            ->mapWithKeys(fn (int $id): array => [$id => (int) ($counts[$id] ?? 0)])
            ->all();
    }

    /**
     * Expected OTP totalled over the period.
     *
     * @return array<int, int>
     */
    public function expectedOtps(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $expected = DailyCallerOtp::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('employee_id, SUM(expected_otp) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        return $employeeIds
            ->mapWithKeys(fn (int $id): array => [$id => (int) ($expected[$id] ?? 0)])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Scoping helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow the visible set with the dashboard/report filters. Each
     * filter is applied against the user's own visible set, so a filter
     * can never widen what someone is allowed to see.
     *
     * @param  array{cluster_id?: int|string|null, manager_id?: int|string|null, team_leader_id?: int|string|null, caller_id?: int|string|null}  $filters
     */
    public function filterEmployeeIds(User $user, array $filters = []): Collection
    {
        $ids = $this->visibleEmployeeIds($user);

        foreach (['caller_id', 'team_leader_id', 'manager_id', 'cluster_id'] as $key) {
            $id = $filters[$key] ?? null;

            if (blank($id)) {
                continue;
            }

            $employee = Employee::find((int) $id);

            if ($employee) {
                $ids = $ids->intersect(HierarchyHelper::subordinateIds($employee))->values();
            }

            break;
        }

        // "Role" narrows the list to one level of the hierarchy — pick
        // Team Leader and you get team leaders only. Left blank, everyone
        // in scope is listed, ordered down the hierarchy.
        if (filled($filters['role'] ?? null)) {
            $ids = Employee::query()
                ->whereIn('id', $ids)
                ->where('designation', (int) $filters['role'])
                ->pluck('id');
        }

        return $ids->values();
    }

    /**
     * Dropdown options for one designation, limited to what the user can see.
     *
     * @return array<int, string>
     */
    public function employeeOptions(User $user, int $designation): array
    {
        return Employee::query()
            ->whereIn('id', $this->visibleEmployeeIds($user))
            ->where('designation', $designation)
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => EmployeeOptions::label($employee),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Table rows & rollups
    |--------------------------------------------------------------------------
    */

    /**
     * One row per employee for a single day. Thin wrapper over rows().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyRows(Collection $employeeIds, Carbon $date): Collection
    {
        return $this->rows($employeeIds, $date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    /**
     * One row per employee for a period — commitment, declared
     * achievement, module pipeline, attendance and OTP. Everything in the
     * module renders from this.
     *
     * Over a multi-day period the commitment/achievement figures are the
     * sum of that employee's daily commitments, `stage` is their most
     * recent commitment, and `changes` counts how many times anything
     * moved (each log row is written only when a value actually changed).
     *
     * Rows come back ordered down the hierarchy — Cluster Manager, then
     * their Managers, then those Managers' Team Leaders, then the callers
     * under each Team Leader — so an unfiltered list reads as an org chart.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $employeeIds, Carbon $start, Carbon $end): Collection
    {
        $employeeIds = $employeeIds->unique()->values();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $employees = $this->hierarchicalOrder(
            Employee::query()->whereIn('id', $employeeIds)->get()
        );

        $presentDays = $this->presentDays($employeeIds, $start, $end);
        $actualOtps = $this->otpCounts($employeeIds, $start, $end);
        $expectedOtps = $this->expectedOtps($employeeIds, $start, $end);
        $pipeline = $this->pipeline($employeeIds, $start, $end);

        $commitments = DailyCommitment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->with('entries')
            ->withCount('logs')
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use (
            $presentDays, $actualOtps, $expectedOtps, $commitments, $pipeline
        ): array {
            /** @var Collection<int, DailyCommitment> $own */
            $own = $commitments[$employee->id] ?? collect();

            $latest = $own->last();
            $stage = $latest?->commitment_stage;

            $amountCommitments = $own->filter(fn (DailyCommitment $c): bool => ! $c->commitment_stage->isCount());
            $countCommitments = $own->filter(fn (DailyCommitment $c): bool => $c->commitment_stage->isCount());

            $actualOtp = $actualOtps[$employee->id] ?? 0;
            $expectedOtp = $expectedOtps[$employee->id] ?? 0;

            $targetAmount = (float) $amountCommitments->sum(fn (DailyCommitment $c): float => $c->target());
            $achievedAmount = (float) $amountCommitments->sum(
                fn (DailyCommitment $c): float => $this->achievementFromEntries($c->entries, $c->commitment_stage)['amount']
            );

            $targetCount = (int) $countCommitments->sum(fn (DailyCommitment $c): float => $c->target());
            // An OTP commitment is measured by cases opened in the period,
            // which already carries its own date boundary.
            $achievedCount = $countCommitments->isNotEmpty() ? $actualOtp : 0;

            $isCountMode = $amountCommitments->isEmpty() && $countCommitments->isNotEmpty();

            $target = $isCountMode ? (float) $targetCount : $targetAmount;
            $achieved = $isCountMode ? (float) $achievedCount : $achievedAmount;

            $entries = $own->flatMap(fn (DailyCommitment $c) => $c->entries);

            $result = $own->isNotEmpty()
                ? CommitmentResult::decide(
                    $target,
                    $achieved,
                    dayClosed: $own->every(fn (DailyCommitment $c): bool => $c->isClosed()),
                )
                : null;

            return [
                'employee' => $employee,
                'designation' => $employee->designation,
                'present' => ($presentDays[$employee->id] ?? 0) > 0,
                'present_days' => $presentDays[$employee->id] ?? 0,
                'commitment' => $latest,
                'commitments' => $own,
                'days' => $own->count(),
                'changes' => (int) $own->sum('logs_count'),
                'stage' => $stage,
                'count_mode' => $isCountMode,
                'current_stage' => $latest?->current_stage,
                'target' => $target,
                'achieved' => $achieved,
                'pending' => max($target - $achieved, 0),
                'percentage' => $target > 0 ? round(($achieved / $target) * 100, 1) : 0.0,
                'result' => $result,
                'submitted' => $own->isNotEmpty() && $own->every(fn (DailyCommitment $c): bool => $c->submitted_at !== null),
                'expected_otp' => $expectedOtp,
                'actual_otp' => $actualOtp,
                'otp_percentage' => $expectedOtp > 0 ? round(($actualOtp / $expectedOtp) * 100, 1) : 0.0,
                'entries' => $entries,
                'breakdown' => $this->entryBreakdown($entries),
                'pipeline' => $pipeline[$employee->id] ?? ['stages' => [], 'total_amount' => 0.0, 'total_count' => 0],
            ];
        })->values();
    }

    /**
     * Order employees down the reporting tree: each Cluster Manager, then
     * their Managers, then each Manager's Team Leaders, then that Team
     * Leader's callers. A caller's manager/cluster is resolved through
     * their Team Leader rather than from their own columns, which are not
     * always populated.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    public function hierarchicalOrder(Collection $employees): Collection
    {
        $all = Employee::query()
            ->get(['id', 'emp_name', 'designation', 'superviser_id', 'manager_id', 'cluster_id'])
            ->keyBy('id');

        $level = fn (?int $designation): int => match ($designation) {
            Employee::DESIGNATION_CLUSTER => 0,
            Employee::DESIGNATION_MANAGER => 1,
            Employee::DESIGNATION_TEAM_LEADER => 2,
            Employee::DESIGNATION_CALLER => 3,
            default => 4,
        };

        return $employees->sortBy(function (Employee $employee) use ($all, $level): string {
            $leader = $employee->designation === Employee::DESIGNATION_TEAM_LEADER
                ? $employee
                : ($all[$employee->superviser_id] ?? null);

            $manager = $employee->designation === Employee::DESIGNATION_MANAGER
                ? $employee
                : ($all[$leader?->manager_id ?? $employee->manager_id] ?? null);

            $cluster = $employee->designation === Employee::DESIGNATION_CLUSTER
                ? $employee
                : ($all[$manager?->cluster_id ?? $employee->cluster_id] ?? null);

            // Joined with a control character rather than a printable one:
            // an empty segment (a Cluster Manager has no manager above it)
            // must sort BEFORE a filled one, and every printable character
            // outranks \x1f.
            return implode("\x1f", [
                $cluster?->emp_name ?? '~',
                $employee->designation === Employee::DESIGNATION_CLUSTER ? '' : ($manager?->emp_name ?? '~'),
                in_array($employee->designation, [Employee::DESIGNATION_CLUSTER, Employee::DESIGNATION_MANAGER], true)
                    ? ''
                    : ($leader?->emp_name ?? '~'),
                $level($employee->designation),
                $employee->emp_name,
            ]);
        })->values();
    }

    /**
     * Roll a set of daily rows up into the dashboard/report headline
     * numbers. Amount-based and count-based (OTP) commitments are totalled
     * separately — adding rupees to a headcount would be meaningless.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarise(Collection $rows): array
    {
        $withCommitment = $rows->filter(fn (array $row): bool => $row['commitment'] !== null);
        $amountRows = $withCommitment->filter(fn (array $row): bool => ! $row['count_mode']);
        $countRows = $withCommitment->filter(fn (array $row): bool => $row['count_mode']);

        $committedAmount = (float) $amountRows->sum('target');
        $achievedAmount = (float) $amountRows->sum('achieved');

        $expectedOtp = (int) $rows->sum('expected_otp');
        $actualOtp = (int) $rows->sum('actual_otp');

        $stageTotals = collect(CommitmentStage::reportable())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => ['amount' => 0.0, 'count' => 0]])
            ->all();

        $pipelineTotals = collect(CommitmentStage::ladder())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => ['amount' => 0.0, 'count' => 0]])
            ->all();

        $pipelineAmount = 0.0;
        $pipelineCount = 0;

        foreach ($rows as $row) {
            foreach ($row['breakdown']['stages'] ?? [] as $stageValue => $totals) {
                $stageTotals[$stageValue]['amount'] += $totals['amount'];
                $stageTotals[$stageValue]['count'] += $totals['count'];
            }

            $stageTotals[CommitmentStage::Dropped->value]['count'] += $row['breakdown']['dropped'] ?? 0;
            $stageTotals[CommitmentStage::Rejected->value]['count'] += $row['breakdown']['rejected'] ?? 0;

            foreach ($row['pipeline']['stages'] ?? [] as $stageValue => $totals) {
                $pipelineTotals[$stageValue]['amount'] += $totals['amount'];
                $pipelineTotals[$stageValue]['count'] += $totals['count'];
            }

            $pipelineAmount += $row['pipeline']['total_amount'] ?? 0.0;
            $pipelineCount += $row['pipeline']['total_count'] ?? 0;
        }

        return [
            'people' => $rows->count(),
            'with_commitment' => $withCommitment->count(),
            'without_commitment' => $rows->count() - $withCommitment->count(),
            'submitted' => $withCommitment->where('submitted', true)->count(),
            'committed_amount' => $committedAmount,
            'achieved_amount' => $achievedAmount,
            'pending_amount' => max($committedAmount - $achievedAmount, 0),
            'percentage' => $committedAmount > 0 ? round(($achievedAmount / $committedAmount) * 100, 1) : 0.0,
            'committed_count' => (int) $countRows->sum('target'),
            'achieved_count' => (int) $countRows->sum('achieved'),
            'met' => $withCommitment->where('result', CommitmentResult::Met)->count(),
            'failed' => $withCommitment->where('result', CommitmentResult::Failed)->count(),
            'overachieved' => $withCommitment->where('result', CommitmentResult::Overachieved)->count(),
            'in_progress' => $withCommitment->where('result', CommitmentResult::InProgress)->count(),
            'present' => $rows->where('present', true)->count(),
            'absent' => $rows->where('present', false)->count(),
            'present_days' => (int) $rows->sum('present_days'),
            'changes' => (int) $rows->sum('changes'),
            'expected_otp' => $expectedOtp,
            'actual_otp' => $actualOtp,
            'otp_percentage' => $expectedOtp > 0 ? round(($actualOtp / $expectedOtp) * 100, 1) : 0.0,
            'stage_totals' => $stageTotals,
            'pipeline_totals' => $pipelineTotals,
            'pipeline_amount' => $pipelineAmount,
            'pipeline_count' => $pipelineCount,
        ];
    }

    /**
     * Month-to-date position against this module's own monthly target.
     *
     * MTD achievement is the sum of that month's daily achievements — the
     * same declared fulfilment, just added up — so the month can never
     * contain business no one claimed on a day.
     *
     * DRR (daily run rate) is stated plainly so the dashboard can show the
     * arithmetic: achieved so far / working days elapsed, and what is
     * still required per remaining working day to land the target.
     *
     * @return array{target: float, achieved: float, pending: float, percentage: float,
     *               stage: ?CommitmentStage, is_count: bool, drr: float, required_drr: float,
     *               elapsed_working_days: int, remaining_working_days: int, total_working_days: int}
     */
    public function monthlyPosition(int $employeeId, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $targetRow = MonthlyCommitmentTarget::query()
            ->where('employee_id', $employeeId)
            ->forMonth($month)
            ->first();

        $stage = $targetRow?->stage;
        $target = (float) ($targetRow?->target() ?? 0);
        $isCount = (bool) $stage?->isCount();

        $achieved = 0.0;

        if ($stage !== null) {
            if ($isCount) {
                $achieved = (float) ($this->otpCounts(
                    collect([$employeeId]),
                    $start->copy()->startOfDay(),
                    $end->copy()->endOfDay(),
                )[$employeeId] ?? 0);
            } else {
                $commitments = DailyCommitment::query()
                    ->where('employee_id', $employeeId)
                    ->forMonth($month)
                    ->with('entries')
                    ->get();

                foreach ($commitments as $commitment) {
                    $achieved += $this->achievementFromEntries($commitment->entries, $stage)['amount'];
                }
            }
        }

        $today = today();
        $monthEndForElapsed = $today->lt($end) ? $today : $end;

        $totalWorkingDays = PerformancePeriod::workingDays($start, $end);
        $elapsed = $today->lt($start) ? 0 : PerformancePeriod::workingDays($start, $monthEndForElapsed);
        $remaining = max($totalWorkingDays - $elapsed, 0);

        $pending = max($target - $achieved, 0);

        return [
            'target' => $target,
            'achieved' => $achieved,
            'pending' => $pending,
            'percentage' => $target > 0 ? round(($achieved / $target) * 100, 1) : 0.0,
            'stage' => $stage,
            'is_count' => $isCount,
            'drr' => $elapsed > 0 ? round($achieved / $elapsed, 2) : 0.0,
            'required_drr' => $remaining > 0 ? round($pending / $remaining, 2) : 0.0,
            'elapsed_working_days' => $elapsed,
            'remaining_working_days' => $remaining,
            'total_working_days' => $totalWorkingDays,
        ];
    }

    /**
     * The furthest stage any declared row reached — the day's "current
     * stage" headline.
     *
     * @param  Collection<int, DailyCommitmentEntry>  $entries
     */
    private function highestDeclaredStage(Collection $entries): ?CommitmentStage
    {
        return $entries
            ->map(fn (DailyCommitmentEntry $entry): CommitmentStage => $entry->effectiveStage())
            ->filter(fn (CommitmentStage $stage): bool => $stage->rank() !== null)
            ->sortByDesc(fn (CommitmentStage $stage): int => $stage->rank())
            ->first();
    }

    /**
     * The value a case carries at the stage it reached.
     */
    private function amountAtStage(Customer $customer, ?CommitmentStage $stage): float
    {
        return match ($stage) {
            CommitmentStage::Disbursed => (float) ($customer->sanctioned_loan_amount
                ?? $customer->approved_loan_amount
                ?? $customer->eligible_loan_amount
                ?? 0),
            CommitmentStage::Approved => (float) ($customer->approved_loan_amount
                ?? $customer->sanctioned_loan_amount
                ?? $customer->eligible_loan_amount
                ?? 0),
            null => 0.0,
            default => (float) ($customer->eligible_loan_amount
                ?? $customer->approved_loan_amount
                ?? $customer->sanctioned_loan_amount
                ?? 0),
        };
    }
}
