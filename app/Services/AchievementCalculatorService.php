<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AchievementCalculatorService
{
    /**
     * Banks whose count-achievement deduction is halved.
     *
     * Every other bank (including no bank recorded) is deducted in full.
     * This is the only authoritative bank field on the sale — Customer has
     * no FK to the `banks` table, and Bank MIS never overrides it.
     */
    private const HALF_DEDUCTION_BANKS = ['BFL Prime', 'BFL Growth'];

    /**
     * customers.sanctioned_bank is a free-text column, not an FK — the UI
     * form constrains it to a fixed Select, but CustomerImporter's CSV
     * import column (only ->rules(['max:255']), no `in:` constraint) and
     * OcrFieldExtractionService's OCR-extracted value both write to it
     * without going through that Select, so a variant like "bfl prime",
     * "BFL PRIME", or "BFL-Prime" can genuinely reach this column. The
     * comparison below normalizes case/surrounding whitespace/hyphens on
     * both sides so those variants still match their canonical bank —
     * without merging two genuinely different banks (BFL Prime and BFL
     * Growth are normalized independently and remain distinct), and
     * without any database/schema change.
     */
    private function canonicalBankName(string $bankName): string
    {
        return strtoupper(trim(str_replace('-', ' ', $bankName)));
    }

    /**
     * Memoized per resolved input month, keyed by "Y-m" — every top-level
     * getter below (getCountAchievement, getTarget, etc.) resolves through
     * here, and several of them (e.g. TopPerformerService's per-employee
     * loop) call it dozens of times per request with the same null/current
     * input, so this avoids re-running the existence check on every call.
     *
     * @var array<string, Carbon>
     */
    private array $resolvedMonths = [];

    /**
     * Achievement is disbursal-date based, but a brand-new calendar month
     * starts with zero disbursals until loans already in the pipeline
     * actually clear — that's not "no achievement yet", it's "the month
     * hasn't produced any data yet". Rather than show every dashboard/
     * leaderboard as a wall of zeros for the first several days of each
     * month, fall back to the last month that actually has disbursal data
     * once the real current month has none at all. Only applies when the
     * requested month IS the real current month — an explicitly requested
     * historical month is never overridden.
     */
    public function resolveReferenceMonth(?Carbon $referenceMonth = null): Carbon
    {
        $referenceMonth ??= SelectedMonth::current();

        $key = $referenceMonth->format('Y-m');

        if (array_key_exists($key, $this->resolvedMonths)) {
            return $this->resolvedMonths[$key];
        }

        $resolved = $referenceMonth;

        if ($referenceMonth->isSameMonth(now())) {

            $hasAnyDisbursalThisMonth = Customer::query()
                ->whereBetween('disbursal_date', [
                    $referenceMonth->copy()->startOfMonth(),
                    $referenceMonth->copy()->endOfMonth(),
                ])
                ->exists();

            if (! $hasAnyDisbursalThisMonth) {
                $resolved = $referenceMonth->copy()->subMonthNoOverflow()->startOfMonth();
            }
        }

        return $this->resolvedMonths[$key] = $resolved;
    }

    public function getCountAchievement(Employee $employee, ?Carbon $referenceMonth = null): float
    {
        if ($employee->designation === Employee::DESIGNATION_ADMIN) {

            $customers = Customer::query();
        } else {

            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customers = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        $customers->whereBetween('customers.disbursal_date', [
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        ]);

        return $this->computeAchievementTotals($customers)['count_achievement'];
    }

    /**
     * Single authoritative achievement query.
     *
     * Computes actual/cashback/subvention/docking totals, and the
     * bank-aware count achievement, in one pass. The deduction is applied
     * per customer (bank varies per loan) and summed, not derived from the
     * aggregate totals afterward — a loan's bank determines whether its own
     * cashback+subvention+docking is halved or deducted in full before it's
     * added to the total.
     *
     * @return array{actual: float, cashback: float, subvention: float, docking: float, count_achievement: float}
     */
    public function computeAchievementTotals(Builder $customers): array
    {
        $totals = $this->achievementQuery($customers)->first();

        return [
            'actual' => (float) ($totals->actual ?? 0),
            'cashback' => (float) ($totals->cashback ?? 0),
            'subvention' => (float) ($totals->subvention ?? 0),
            'docking' => (float) ($totals->docking ?? 0),
            'count_achievement' => (float) ($totals->count_achievement ?? 0),
        ];
    }

    /**
     * Same per-loan bank-aware formula as computeAchievementTotals(), but
     * grouped by employee in a single query instead of one query per
     * employee — used where a flat (non-hierarchical) group's achievement
     * is needed per-member, e.g. TopPerformerService ranking every Caller.
     * Only valid for a flat group: a Team Leader/Manager/Cluster's
     * achievement is their whole subordinate tree's customers rolled up
     * under THEIR OWN id, which a plain GROUP BY customers.employee_id
     * cannot produce — those still go through getCountAchievement() per
     * employee.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, float> employee_id => count_achievement
     */
    public function countAchievementByEmployeeId(Collection $employeeIds, ?Carbon $referenceMonth = null): array
    {
        if ($employeeIds->isEmpty()) {
            return [];
        }

        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        $customers = Customer::query()
            ->whereIn('customers.employee_id', $employeeIds)
            ->whereBetween('customers.disbursal_date', [
                $referenceMonth->copy()->startOfMonth(),
                $referenceMonth->copy()->endOfMonth(),
            ]);

        $rows = $this->achievementQuery($customers)
            ->addSelect('customers.employee_id as employee_id')
            ->groupBy('customers.employee_id')
            ->get();

        return $rows
            ->pluck('count_achievement', 'employee_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    private function achievementQuery(Builder $customers): Builder
    {
        return $customers
            ->leftJoin('customer_settlements as cs', function ($join) {
                $join->on('cs.customer_id', '=', 'customers.id')
                    ->where('cs.version', 1);
            })
            ->selectRaw('
                SUM(CASE WHEN cs.mis_disbursal_amount IS NOT NULL THEN cs.mis_disbursal_amount ELSE customers.sanctioned_loan_amount END) as actual,
                SUM(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END) as cashback,
                SUM(CASE WHEN cs.mis_subvention IS NOT NULL THEN cs.mis_subvention ELSE customers.subvention END) as subvention,
                SUM(CASE WHEN cs.mis_docking IS NOT NULL THEN cs.mis_docking ELSE CAST(customers.docking AS DECIMAL(15,2)) END) as docking,
                SUM(
                    COALESCE(CASE WHEN cs.mis_disbursal_amount IS NOT NULL THEN cs.mis_disbursal_amount ELSE customers.sanctioned_loan_amount END, 0)
                    - (
                        (
                            COALESCE(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END, 0)
                            + COALESCE(CASE WHEN cs.mis_subvention IS NOT NULL THEN cs.mis_subvention ELSE customers.subvention END, 0)
                            + COALESCE(CASE WHEN cs.mis_docking IS NOT NULL THEN cs.mis_docking ELSE CAST(customers.docking AS DECIMAL(15,2)) END, 0)
                        )
                        * (CASE WHEN UPPER(TRIM(REPLACE(customers.sanctioned_bank, \'-\', \' \'))) IN (?, ?) THEN 50 ELSE 100 END)
                    )
                ) as count_achievement
            ', array_map($this->canonicalBankName(...), self::HALF_DEDUCTION_BANKS));
    }

    public function getTarget(Employee $employee, ?Carbon $referenceMonth = null): float
    {
        // Deliberately NOT resolveReferenceMonth() here: target is
        // joining/exit-date boundary logic anchored to a real calendar
        // month, not achievement data — it must never silently shift just
        // because the month has no disbursals yet. Callers that need
        // target paired with a (possibly-shifted) achievement month
        // resolve once and pass it in explicitly (see getPerformance()).
        $referenceMonth ??= SelectedMonth::current();

        return $this->getTargetForPeriod(
            $employee,
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth()
        );
    }

    /**
     * Single authoritative target engine, for an arbitrary period.
     *
     * getTarget() (current month) is a thin wrapper around this method —
     * both current-month and historical-period target calculations go
     * through the exact same business rules. The ₹30L understaffed-team
     * top-up structure (thresholds, which callers/TLs are counted) is
     * unchanged from the original current-month-only implementation; only
     * the per-caller target value now comes from
     * getHierarchyCallerTargetForPeriod() instead of a "today"-only calc.
     */
    public function getTargetForPeriod(Employee $employee, Carbon $start, Carbon $end): float
    {
        /*
    |--------------------------------------------------------------------------
    | Caller
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return $this->getCallerTarget($employee);
        }

        /*
    |--------------------------------------------------------------------------
    | Team Leader
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

            $callerIds = HierarchyHelper::callerIds($employee);

            $target = Employee::whereIn('id', $callerIds)
                ->get()
                ->sum(
                    fn (Employee $caller) => $this->getHierarchyCallerTargetForPeriod($caller, $start, $end)
                );

            if ($callerIds->count() < 3) {
                $target += 3000000;
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Manager
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            $target = Employee::whereIn(
                'id',
                HierarchyHelper::callerIds($employee)
            )
                ->get()
                ->sum(fn (Employee $caller) => $this->getHierarchyCallerTargetForPeriod($caller, $start, $end));

            $teamLeaderIds = Employee::where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            foreach ($teamLeaderIds as $tlId) {

                $callerCount = Employee::where('superviser_id', $tlId)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->count();

                if ($callerCount < 3) {
                    $target += 3000000;
                }
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Cluster Manager
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            $target = Employee::whereIn(
                'id',
                HierarchyHelper::callerIds($employee)
            )
                ->get()
                ->sum(
                    fn (Employee $caller) => $this->getHierarchyCallerTargetForPeriod($caller, $start, $end)
                );

            $managerIds = Employee::where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            foreach ($teamLeaderIds as $tlId) {

                $callerCount = Employee::where('superviser_id', $tlId)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->count();

                if ($callerCount < 3) {
                    $target += 3000000;
                }
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_ADMIN) {

            return Employee::where('designation', Employee::DESIGNATION_CALLER)
                ->get()
                ->sum(fn (Employee $caller) => $this->getHierarchyCallerTargetForPeriod($caller, $start, $end));
        }

        return 0;
    }

    public function getPercentage(Employee $employee): float
    {
        return $this->percentageFromAmounts(
            $this->getCountAchievement($employee),
            $this->getTarget($employee)
        );
    }

    /**
     * Single authoritative achievement-percentage formula, for callers
     * that already have the achievement/target amounts on hand (e.g. from
     * a prior getTarget()/getCountAchievement() or getPerformance() call)
     * and would otherwise have to either duplicate this formula or pay for
     * a second, redundant query round-trip by calling getPercentage().
     */
    public function percentageFromAmounts(float $achievement, float $target): float
    {
        if ($target <= 0) {
            return 0;
        }

        return round(($achievement / $target) * 100, 2);
    }

    public function getEligibleCallerCount(Employee $manager, ?Carbon $referenceMonth = null): int
    {
        /*
    |--------------------------------------------------------------------------
    | Get all callers under this Manager
    |--------------------------------------------------------------------------
    */

        $callerIds = HierarchyHelper::callerIds($manager);

        if ($callerIds->isEmpty()) {
            return 0;
        }

        $callers = Employee::whereIn('id', $callerIds)->get();

        /*
    |--------------------------------------------------------------------------
    | Eligible Caller
    |--------------------------------------------------------------------------
    |
    | A caller is eligible if his/her target for the current month is
    | greater than zero. This must use the same reporting-date/exit-status
    | aware target (getHierarchyCallerTarget) as the rest of this service —
    | getCallerTarget() only returns the caller's static category value (or
    | a 2,500,000 default) and is never zero, so it would count a
    | not-yet-eligible new joiner (<10 days into the month) or an
    | already-exited caller as "eligible", inflating the PPP denominator.
    |
    */

        return $callers
            ->filter(fn (Employee $caller) => $this->getHierarchyCallerTarget($caller, $referenceMonth) > 0)
            ->count();
    }

    public function getPPPMultiplier(float $ppp): float
    {
        return match (true) {

            $ppp >= 3100000 => 0.00075,

            $ppp >= 2900000 => 0.00060,

            $ppp >= 2700000 => 0.00050,

            $ppp >= 2500000 => 0.00040,

            $ppp >= 2300000 => 0.00030,

            default => 0,
        };
    }

    /**
     * Single authoritative Manager incentive engine.
     *
     * A Manager's incentive is governed ENTIRELY by the PPP multiplier
     * system (team achievement spread evenly across eligible callers,
     * matched against the PPP bands in getPPPMultiplier()) — it does NOT
     * use the flat per-caller slab table from getIncentiveSlabs(). That
     * table's highest bracket tops out at 11,000,000, but a Manager's team
     * achievement routinely exceeds that, which would otherwise always
     * return the flat capped slab incentive regardless of the team's
     * actual PPP — including when PPP is below the 2,300,000 floor, where
     * a Manager must earn NO incentive at all.
     *
     * @return array{eligible_callers: int, count_achievement: float, ppp: float, multiplier: float, incentive: float}
     */
    public function getManagerIncentiveBreakdown(Employee $manager, ?Carbon $referenceMonth = null): array
    {
        // Resolved once so achievement and eligible-caller-count (which
        // feeds PPP) are paired to the same month even when this falls
        // back to the last month with disbursal data.
        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        $countAchievement = $this->getCountAchievement($manager, $referenceMonth);

        $eligibleCallers = $this->getEligibleCallerCount($manager, $referenceMonth);

        $ppp = $eligibleCallers > 0
            ? $countAchievement / $eligibleCallers
            : 0;

        $multiplier = $this->getPPPMultiplier($ppp);

        return [
            'eligible_callers' => $eligibleCallers,
            'count_achievement' => $countAchievement,
            'ppp' => $ppp,
            'multiplier' => $multiplier,
            'incentive' => $countAchievement * $multiplier,
        ];
    }

    /**
     * Single authoritative Team Leader incentive engine.
     *
     * A Team Leader's incentive is a revenue-share formula over their own
     * caller team, distinct from both the flat caller slab table and the
     * Manager PPP formula:
     *
     * - Team target/achievement reuse the same canonical engines as
     *   everywhere else (getHierarchyCallerTarget() per caller,
     *   computeAchievementTotals() for net bank-aware/MIS-aware
     *   achievement) rather than a separate reimplementation.
     * - Gate: team achievement must reach 90% of team target once the team
     *   has more than 4 callers, otherwise (4 or fewer callers) it must
     *   reach 100% — below the gate, incentive is 0.
     * - Below the gate aside, an understaffed team (< 3 callers) is NOT
     *   separately zeroed here — the existing ₹30L target top-up for
     *   understaffed teams (see getTargetForPeriod()'s Team Leader branch)
     *   is what makes an understaffed team's target harder to hit; this
     *   method does not duplicate that as a second, independent gate. The
     *   ONE exception is a single eligible caller (see below), which IS a
     *   hard zero.
     * - Hard zero: a Team Leader with exactly one eligible caller (current
     *   month target > 0, via getEligibleCallerCount()) earns no incentive
     *   at all, regardless of achievement — checked before, and
     *   independently of, the achievement gate below.
     * - Revenue share: 2% of team achievement, minus a ₹30,000-per-caller
     *   cost, taken at 6/7/8% depending on team size (3/4/5+ callers), plus
     *   5% of achievement in excess of team target; a further 10% bonus
     *   applies when every caller individually met their own target.
     *
     * @return array{caller_count: int, eligible_callers: int, team_target: float, team_achievement: float, achievement_percentage: float, required_percentage: float, meets_gate: bool, all_callers_achieved: bool, incentive: float}
     */
    public function getTeamLeaderIncentiveBreakdown(Employee $teamLeader, ?Carbon $referenceMonth = null): array
    {
        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        $callerIds = HierarchyHelper::callerIds($teamLeader);

        $callerCount = $callerIds->count();

        $eligibleCallers = $this->getEligibleCallerCount($teamLeader, $referenceMonth);

        $callers = Employee::whereIn('id', $callerIds)->get();

        $teamTarget = $callers->sum(
            fn (Employee $caller) => $this->getHierarchyCallerTarget($caller, $referenceMonth)
        );

        $teamCustomers = Customer::query()->whereIn('employee_id', $callerIds);

        $teamCustomers->whereBetween('customers.disbursal_date', [
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        ]);

        $teamAchievement = $this->computeAchievementTotals($teamCustomers)['count_achievement'];

        $achievementPercentage = $teamTarget > 0
            ? ($teamAchievement / $teamTarget) * 100
            : 0.0;

        $requiredPercentage = $callerCount > 4 ? 90.0 : 100.0;

        $meetsGate = $achievementPercentage >= $requiredPercentage;

        if ($eligibleCallers === 1) {
            return [
                'caller_count' => $callerCount,
                'eligible_callers' => $eligibleCallers,
                'team_target' => $teamTarget,
                'team_achievement' => $teamAchievement,
                'achievement_percentage' => round($achievementPercentage, 2),
                'required_percentage' => $requiredPercentage,
                'meets_gate' => false,
                'all_callers_achieved' => false,
                'incentive' => 0.0,
            ];
        }

        if (! $meetsGate) {
            return [
                'caller_count' => $callerCount,
                'eligible_callers' => $eligibleCallers,
                'team_target' => $teamTarget,
                'team_achievement' => $teamAchievement,
                'achievement_percentage' => round($achievementPercentage, 2),
                'required_percentage' => $requiredPercentage,
                'meets_gate' => false,
                'all_callers_achieved' => false,
                'incentive' => 0.0,
            ];
        }

        $revenue = $teamAchievement * 0.02;

        $targetRevenue = $teamTarget * 0.02;

        $grossRevenue = max(0, $revenue - ($callerCount * 30000));

        $extraRevenue = max(0, $revenue - $targetRevenue);

        $grossRevenuePercent = match (true) {
            $callerCount === 3 => 0.06,
            $callerCount === 4 => 0.07,
            $callerCount >= 5 => 0.08,
            default => 0.0,
        };

        $baseIncentive = ($grossRevenue * $grossRevenuePercent) + ($extraRevenue * 0.05);

        $allCallersAchieved = $callers->every(
            fn (Employee $caller) => $this->getCountAchievement($caller, $referenceMonth)
                >= $this->getHierarchyCallerTarget($caller, $referenceMonth)
        );

        if ($callerCount >= 3 && $allCallersAchieved) {
            $baseIncentive *= 1.10;
        }

        return [
            'caller_count' => $callerCount,
            'eligible_callers' => $eligibleCallers,
            'team_target' => $teamTarget,
            'team_achievement' => $teamAchievement,
            'achievement_percentage' => round($achievementPercentage, 2),
            'required_percentage' => $requiredPercentage,
            'meets_gate' => true,
            'all_callers_achieved' => $allCallersAchieved,
            'incentive' => round(max($baseIncentive, 0)),
        ];
    }

    public function getPerformance(?Employee $employee, ?Carbon $referenceMonth = null): array
    {
        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        /*
    |--------------------------------------------------------------------------
    | COMPANY VIEW
    |--------------------------------------------------------------------------
    |
    | Company-wide view is triggered by either $employee === null, or an
    | Employee record whose own designation is Admin — matching
    | getCountAchievement()/getTargetForPeriod(), which already treat
    | DESIGNATION_ADMIN as company-wide. Without this, an Admin user
    | modeled as a linked Employee record (rather than no employee at all)
    | would incorrectly fall through to the individual-employee branch.
    |
    | When any other Employee is passed, ALWAYS calculate for that
    | employee, even when the logged-in user is Admin.
    |
    */

        $isCompanyView = $employee === null
            || $employee->designation === Employee::DESIGNATION_ADMIN;

        /*
    |--------------------------------------------------------------------------
    | Customer Scope
    |--------------------------------------------------------------------------
    */

        if ($isCompanyView) {

            // Admin dashboard: all company customers
            $customers = Customer::query();
        } else {

            // Employee/Team table: only this employee's hierarchy
            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customers = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        /*
    |--------------------------------------------------------------------------
    | Reference Month
    |--------------------------------------------------------------------------
    */

        $customers->whereBetween('customers.disbursal_date', [
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        ]);

        /*
    |--------------------------------------------------------------------------
    | Actual / Deductions / Count Achievement
    |--------------------------------------------------------------------------
    */

        $totals = $this->computeAchievementTotals($customers);

        $actual = $totals['actual'];

        $cashback = $totals['cashback'];

        $subvention = $totals['subvention'];

        $docking = $totals['docking'];

        $countAchievement = $totals['count_achievement'];

        /*
    |--------------------------------------------------------------------------
    | Target
    |--------------------------------------------------------------------------
    */

        if ($isCompanyView) {

            // Admin dashboard: sum of all caller targets, hierarchy-adjusted
            // for new joiners/exits so it matches the drill-down totals.
            $target = Employee::query()
                ->where(
                    'designation',
                    Employee::DESIGNATION_CALLER
                )
                ->get()
                ->sum(
                    fn (Employee $caller) => $this->getHierarchyCallerTarget($caller, $referenceMonth)
                );
        } else {

            // Teams table / employee dashboard:
            // target belongs to the employee being evaluated
            $target = $this->getTarget($employee, $referenceMonth);
        }

        /*
    |--------------------------------------------------------------------------
    | Percentage
    |--------------------------------------------------------------------------
    */

        $percentage = $target > 0
            ? round(
                ($countAchievement / $target) * 100,
                2
            )
            : 0;

        /*
    |--------------------------------------------------------------------------
    | Result
    |--------------------------------------------------------------------------
    */

        return [
            'target_category' => $employee?->category,
            'target' => (float) $target,
            'actual' => $actual,
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => $docking,
            'count_achievement' => $countAchievement,
            'percentage' => $percentage,
            'incentive' => match ($employee?->designation) {
                Employee::DESIGNATION_MANAGER => $this->getManagerIncentiveBreakdown($employee, $referenceMonth)['incentive'],
                Employee::DESIGNATION_TEAM_LEADER => $this->getTeamLeaderIncentiveBreakdown($employee, $referenceMonth)['incentive'],
                default => $this->getIncentive($countAchievement),
            },
        ];
    }

    public function getIncentive(float $countAchievement): float
    {
        $incentive = 0;

        foreach ($this->getIncentiveSlabs() as $target => $amount) {

            if ($countAchievement >= $target) {
                $incentive = $amount;
            }
        }

        return $incentive;
    }

    public function getIncentiveSlabs(): array
    {
        return [
            2500000 => 4000,
            3000000 => 5500,
            3500000 => 7000,
            4000000 => 9000,
            4500000 => 12000,
            5000000 => 15000,
            5500000 => 18000,
            6000000 => 22000,
            6500000 => 26000,
            7000000 => 30000,
            7500000 => 35000,
            8000000 => 40000,
            8500000 => 45000,
            9000000 => 50000,
            9500000 => 55000,
            10000000 => 60000,
            10500000 => 65000,
            11000000 => 70000,
        ];
    }

    public function getNextIncentiveSlab(float $countAchievement): ?array
    {
        foreach ($this->getIncentiveSlabs() as $target => $incentive) {

            if ($countAchievement < $target) {
                return [
                    'target' => (float) $target,
                    'incentive' => (float) $incentive,
                    'remaining' => max($target - $countAchievement, 0),
                ];
            }
        }

        return null;
    }

    private function getCallerTarget(Employee $employee): float
    {

        /*
    |--------------------------------------------------------------------------
    | CALLER OWN TARGET
    |--------------------------------------------------------------------------
    |
    | Caller target is ALWAYS based on assigned category.
    |
    | DOJ, reporting_date, joining date and exit date do NOT affect
    | the caller's own target.
    |
    */

        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
    }

    public function getHierarchyCallerTarget(Employee $employee, ?Carbon $referenceMonth = null): float
    {
        // See getTarget() above — target boundary logic must anchor to the
        // real requested month, never the achievement-driven fallback.
        $referenceMonth ??= SelectedMonth::current();

        return $this->getHierarchyCallerTargetForPeriod(
            $employee,
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth()
        );
    }

    /**
     * Single authoritative per-caller hierarchy target engine, for an
     * arbitrary period. getHierarchyCallerTarget() (current month) is a
     * thin wrapper around this — decomposes the period into full calendar
     * months and evaluates each with the identical joining/exit business
     * rules, so a month never produces a different target depending on
     * whether it's viewed as "the current month" or as a historical period.
     */
    public function getHierarchyCallerTargetForPeriod(Employee $employee, Carbon $start, Carbon $end): float
    {
        $total = 0.0;
        $cursor = $start->copy()->startOfMonth();
        $periodEnd = $end->copy();

        while ($cursor->lte($periodEnd)) {
            $total += $this->hierarchyCallerTargetForMonth(
                $employee,
                $cursor->copy()->startOfMonth(),
                $cursor->copy()->endOfMonth()
            );

            $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
        }

        return $total;
    }

    /**
     * Evaluates one caller's hierarchy target for a single full calendar
     * month [$monthStart, $monthEnd].
     *
     * $today is used only to cap "worked days" counting at the present day
     * for a month that is still ongoing (the current month) — for a month
     * that has already fully elapsed, the count runs to the month's own
     * end. This single substitution (today vs. month-end) is what makes
     * current-month and historical evaluation share one formula: when
     * $monthEnd is in the future (the current month), it behaves exactly
     * like the original today-only implementation; when $monthEnd is in
     * the past, it behaves like a closed historical month.
     */
    private function hierarchyCallerTargetForMonth(Employee $employee, Carbon $monthStart, Carbon $monthEnd): float
    {
        $today = Carbon::today();

        /*
    |--------------------------------------------------------------------------
    | INACTIVE / EXITED EMPLOYEE
    |--------------------------------------------------------------------------
    */

        if (
            strtolower((string) $employee->exit_status) === 'yes'
            && ! empty($employee->exit_date)
        ) {
            $exitDate = Carbon::parse($employee->exit_date);

            // Exited during this month
            if ($exitDate->between($monthStart, $monthEnd)) {
                return $exitDate->day >= 10
                    ? 1500000
                    : 0;
            }

            // Exited before this month
            if ($exitDate->lt($monthStart)) {
                return 0;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | CATEGORY TARGET
    |--------------------------------------------------------------------------
    */

        $categoryTarget = is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;

        /*
    |--------------------------------------------------------------------------
    | NO REPORTING DATE
    |--------------------------------------------------------------------------
    */

        if (empty($employee->reporting_date)) {
            return $categoryTarget;
        }

        $reportingDate = Carbon::parse($employee->reporting_date);

        /*
    |--------------------------------------------------------------------------
    | NOT YET JOINED AS OF THIS MONTH
    |--------------------------------------------------------------------------
    |
    | Only meaningful for a month that has already fully elapsed — for the
    | still-ongoing current month this can't be distinguished from a
    | future-dated data-entry anomaly, so behavior there is unchanged from
    | the original current-month-only logic (falls through to category
    | target, exactly as before).
    |
    */

        if ($reportingDate->gt($monthEnd) && $monthEnd->lt($today)) {
            return 0;
        }

        /*
    |--------------------------------------------------------------------------
    | REPORTING STARTED DURING THIS MONTH
    |--------------------------------------------------------------------------
    */

        if ($reportingDate->between($monthStart, $monthEnd)) {
            $effectiveEnd = $monthEnd->lt($today) ? $monthEnd : $today;

            $workedDays = $reportingDate->diffInDays($effectiveEnd) + 1;

            return $workedDays >= 10
                ? 1500000
                : 0;
        }

        /*
    |--------------------------------------------------------------------------
    | EXISTING ACTIVE EMPLOYEE
    |--------------------------------------------------------------------------
    */

        return $categoryTarget;
    }
}
