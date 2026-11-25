<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\TargetCategory;
use App\Support\HierarchyHelper;
use App\Support\ReportingTimeline;
use App\Support\SelectedMonth;
use App\Support\TargetCategoryTimeline;
use App\Support\TargetLedger;
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
     * The in-house products whose cashback / subvention / docking are
     * reported as one group on the dashboard (user decision 2026-09-27),
     * with every other sanctioning bank (including BFL RSL) in the second
     * group — their deduction policies differ, so the two are read apart.
     *
     * @var list<string>
     */
    public const IN_HOUSE_SPLIT_BANKS = ['BFL Prime', 'BFL Growth', 'BFL SOL'];

    /**
     * The incentive schemes, as returned by incentiveSchemeFor().
     */
    public const INCENTIVE_SCHEME_SLAB = 'slab';

    public const INCENTIVE_SCHEME_TEAM_LEADER = 'team_leader';

    public const INCENTIVE_SCHEME_MANAGER = 'manager';

    /**
     * Designations paid on the Manager PPP scheme. A Cluster Manager is paid
     * exactly like a Manager (user decision 2026-11-16) — never the flat
     * caller slab, which a branch's achievement always tops out.
     *
     * @var list<int>
     */
    public const MANAGER_SCHEME_DESIGNATIONS = [
        Employee::DESIGNATION_MANAGER,
        Employee::DESIGNATION_CLUSTER,
    ];

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
        $referenceMonth = $this->resolveReferenceMonth($referenceMonth);

        return $this->computeAchievementTotals($this->disbursedCustomersFor(
            $employee,
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        ))['count_achievement'];
    }

    /**
     * The customers disbursed in [$start, $end] that count for $employee.
     *
     * Admin: the whole company. Anyone else: their own cases plus their
     * branch's, each case counted for the team its owner was in ON ITS
     * DISBURSAL DATE (HierarchyHelper::scopeToBranchDuring). If A reports to
     * B until 25 Oct and to C from 26 Oct, A's cases disbursed up to 25 Oct
     * stay with B and later ones go to C — a reporting change never moves
     * achievement that was already earned.
     *
     * $within keeps only the days $employee sat in $within's branch (their
     * contribution to that boss); $callersOnly drops everyone but callers.
     */
    public function disbursedCustomersFor(
        Employee $employee,
        Carbon $start,
        Carbon $end,
        ?Employee $within = null,
        bool $callersOnly = false,
    ): Builder {
        if ($employee->designation === Employee::DESIGNATION_ADMIN) {
            return Customer::query()->whereBetween('customers.disbursal_date', [$start, $end]);
        }

        return HierarchyHelper::scopeToBranchDuring(
            Customer::query(),
            $employee,
            $start,
            $end,
            'customers.employee_id',
            'customers.disbursal_date',
            $within,
            $callersOnly,
        );
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
     * The *_bfl / *_other pairs split each deduction between loans
     * sanctioned by IN_HOUSE_SPLIT_BANKS and everything else; each pair
     * always adds up to the total.
     *
     * @return array{actual: float, cashback: float, subvention: float, docking: float, count_achievement: float, cashback_bfl: float, cashback_other: float, subvention_bfl: float, subvention_other: float, docking_bfl: float, docking_other: float}
     */
    public function computeAchievementTotals(Builder $customers): array
    {
        $totals = $this->achievementQuery($customers)->first();

        $cashback = (float) ($totals->cashback ?? 0);
        $subvention = (float) ($totals->subvention ?? 0);
        $docking = (float) ($totals->docking ?? 0);
        $cashbackBfl = (float) ($totals->cashback_bfl ?? 0);
        $subventionBfl = (float) ($totals->subvention_bfl ?? 0);
        $dockingBfl = (float) ($totals->docking_bfl ?? 0);

        return [
            'actual' => (float) ($totals->actual ?? 0),
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => $docking,
            'count_achievement' => (float) ($totals->count_achievement ?? 0),
            'cashback_bfl' => $cashbackBfl,
            'cashback_other' => $cashback - $cashbackBfl,
            'subvention_bfl' => $subventionBfl,
            'subvention_other' => $subvention - $subventionBfl,
            'docking_bfl' => $dockingBfl,
            'docking_other' => $docking - $dockingBfl,
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
                SUM(CASE WHEN UPPER(TRIM(REPLACE(customers.sanctioned_bank, \'-\', \' \'))) IN (?, ?, ?)
                    THEN COALESCE(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END, 0) ELSE 0 END) as cashback_bfl,
                SUM(CASE WHEN UPPER(TRIM(REPLACE(customers.sanctioned_bank, \'-\', \' \'))) IN (?, ?, ?)
                    THEN COALESCE(CASE WHEN cs.mis_subvention IS NOT NULL THEN cs.mis_subvention ELSE customers.subvention END, 0) ELSE 0 END) as subvention_bfl,
                SUM(CASE WHEN UPPER(TRIM(REPLACE(customers.sanctioned_bank, \'-\', \' \'))) IN (?, ?, ?)
                    THEN COALESCE(CASE WHEN cs.mis_docking IS NOT NULL THEN cs.mis_docking ELSE CAST(customers.docking AS DECIMAL(15,2)) END, 0) ELSE 0 END) as docking_bfl,
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
            ', [
                // Bindings in the order the placeholders appear: the three
                // in-house splits, then the half-deduction banks.
                ...$splitBanks = array_map($this->canonicalBankName(...), self::IN_HOUSE_SPLIT_BANKS),
                ...$splitBanks,
                ...$splitBanks,
                ...array_map($this->canonicalBankName(...), self::HALF_DEDUCTION_BANKS),
            ]);
    }

    public function getTarget(Employee $employee, ?Carbon $referenceMonth = null, ?Employee $within = null): float
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
            $referenceMonth->copy()->endOfMonth(),
            $within,
        );
    }

    /**
     * Single authoritative target engine, for an arbitrary period: the sum
     * of the calendar months it touches, each read off that month's
     * TargetLedger, where every target rule lives (consolidated with the
     * user 2026-11-24).
     *
     * Admin: the company target. Anyone else on their own: the target on
     * their own screen — a caller's full category amount for any month they
     * were on the rolls, a leader's team target. With $within: the part of
     * their month target that counts in $within's (a caller's team target
     * or a leader's target, split by the days spent in it), so the rows of
     * a team listing add up to the boss's own figure.
     *
     * Each month is counted as the position held on its last day, so after
     * a promotion or demotion the months before its effective date keep the
     * old position.
     */
    public function getTargetForPeriod(Employee $employee, Carbon $start, Carbon $end, ?Employee $within = null): float
    {
        $target = 0.0;

        foreach ($this->monthsOf($start, $end) as $month) {
            $ledger = TargetLedger::forMonth($month);

            $target += match (true) {
                $employee->designation === Employee::DESIGNATION_ADMIN => $ledger->companyTarget(),
                $within !== null => $ledger->contribution($employee->id, $within->id),
                default => $ledger->ownTarget($employee->id),
            };
        }

        return round($target, 2);
    }

    /**
     * The first day of every calendar month [$start, $end] touches.
     *
     * @return list<Carbon>
     */
    private function monthsOf(Carbon $start, Carbon $end): array
    {
        $months = [];

        for ($cursor = $start->copy()->startOfMonth(); $cursor->lte($end); $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth()) {
            $months[] = $cursor->copy();
        }

        return $months;
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
    | Eligible Caller
    |--------------------------------------------------------------------------
    |
    | A caller is eligible if they count in this branch's headcount for the
    | month — at least ReportingTimeline::MIN_HEADCOUNT_DAYS in it, so a
    | caller moved mid-month can count in both teams — AND their target for
    | the month is greater than zero. That is their TEAM target
    | (TargetLedger::target()), never the full category amount on their own
    | screen, which would count a not-yet-eligible new joiner (<10 days
    | into the month) as "eligible", inflating the PPP denominator.
    |
    */

        $referenceMonth ??= SelectedMonth::current();

        $callerIds = ReportingTimeline::forMonth($referenceMonth)->headcountCallerIds($manager->id);

        if ($callerIds === []) {
            return 0;
        }

        $ledger = TargetLedger::forMonth($referenceMonth);

        return count(array_filter($callerIds, fn (int $callerId): bool => $ledger->target($callerId) > 0));
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
     * Single authoritative Manager incentive engine — also the Cluster
     * Manager's (see MANAGER_SCHEME_DESIGNATIONS), over their whole branch.
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
     *   everywhere else (the Team Leader's own TargetLedger target, ₹80L
     *   minimum included; computeAchievementTotals() for net
     *   bank-aware/MIS-aware achievement) rather than a separate
     *   reimplementation.
     * - Gate: team achievement must reach 90% of team target once the team
     *   has more than 4 callers, otherwise (4 or fewer callers) it must
     *   reach 100% — below the gate, incentive is 0.
     * - Below the gate aside, a small team is NOT separately zeroed here —
     *   the ₹80L Team Leader minimum in the target is what makes a small
     *   team's target harder to hit; this method does not duplicate that as
     *   a second, independent gate. The ONE exception is a single eligible
     *   caller (see below), which IS a hard zero.
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
        $monthStart = $referenceMonth->copy()->startOfMonth();
        $monthEnd = $referenceMonth->copy()->endOfMonth();

        // The month is read off the reporting tree of each of its days: a
        // caller is in this team's headcount if they spent at least
        // ReportingTimeline::MIN_HEADCOUNT_DAYS in it, the team target is
        // the Team Leader's own month target, and the achievement is the cases
        // disbursed while each caller was in the team.
        $timeline = ReportingTimeline::forMonth($referenceMonth);

        $callers = Employee::whereIn('id', $timeline->headcountCallerIds($teamLeader->id))->get();

        $callerCount = $callers->count();

        $ledger = TargetLedger::forMonth($referenceMonth);

        $eligibleCallers = $callers
            ->filter(fn (Employee $caller) => $ledger->target($caller->id) > 0)
            ->count();

        // Each caller's share of their team target that counts here.
        $targetShares = $ledger->contributions($teamLeader->id, Employee::DESIGNATION_CALLER);

        // The gate and the over-target bonus judge the team on the Team
        // Leader's own target — the callers' shares plus the ₹80L minimum
        // (user decision 2026-11-24), the same figure on their screen.
        $teamTarget = $ledger->target($teamLeader->id);

        $teamAchievement = $this->computeAchievementTotals(
            $this->disbursedCustomersFor($teamLeader, $monthStart, $monthEnd, callersOnly: true)
        )['count_achievement'];

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

        // Each caller against the part of their month spent in this team.
        $allCallersAchieved = $callers->every(
            fn (Employee $caller) => $this->computeAchievementTotals(
                $this->disbursedCustomersFor($caller, $monthStart, $monthEnd, within: $teamLeader)
            )['count_achievement'] >= ($targetShares[$caller->id] ?? 0.0)
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

    /**
     * Target, achievement and incentive for one employee (or the company)
     * for one month.
     *
     * With $within, target and achievement are $employee's contribution to
     * $within's figures — only the days $employee sat in $within's branch —
     * so the rows of a team listing add up to the team's own total even
     * when somebody moved in or out during the month. The incentive is
     * always the employee's own.
     */
    public function getPerformance(?Employee $employee, ?Carbon $referenceMonth = null, ?Employee $within = null): array
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
            $customers = Customer::query()->whereBetween('customers.disbursal_date', [
                $referenceMonth->copy()->startOfMonth(),
                $referenceMonth->copy()->endOfMonth(),
            ]);
        } else {

            // Employee/Team table: this employee's hierarchy, each case
            // counted for the team its owner was in on its disbursal date.
            $customers = $this->disbursedCustomersFor(
                $employee,
                $referenceMonth->copy()->startOfMonth(),
                $referenceMonth->copy()->endOfMonth(),
                $within,
            );
        }

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

            // Admin dashboard: everything that reaches the top of the tree —
            // every branch, Team Leader minimums included, plus anybody with
            // no boss on the rolls (TargetLedger::brokenLineIds()).
            $target = TargetLedger::forMonth($referenceMonth)->companyTarget();
        } else {

            // Teams table / employee dashboard:
            // target belongs to the employee being evaluated
            $target = $this->getTarget($employee, $referenceMonth, $within);
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
            'cashback_bfl' => $totals['cashback_bfl'],
            'cashback_other' => $totals['cashback_other'],
            'subvention_bfl' => $totals['subvention_bfl'],
            'subvention_other' => $totals['subvention_other'],
            'docking_bfl' => $totals['docking_bfl'],
            'docking_other' => $totals['docking_other'],
            'count_achievement' => $countAchievement,
            'percentage' => $percentage,
            'incentive' => $employee
                ? $this->getIncentiveFor($employee, $referenceMonth, $countAchievement)
                : $this->getIncentive($countAchievement),
        ];
    }

    /**
     * The designation whose incentive scheme pays $employee's month: the
     * one held on its last day (ReportingTimeline::designationOn), so a
     * Manager promoted in November is still paid October as a Manager.
     * Today's designation for the current month, or with no designation
     * change recorded.
     */
    public function designationFor(Employee $employee, ?Carbon $referenceMonth = null): ?int
    {
        $referenceMonth ??= SelectedMonth::current();

        return ReportingTimeline::designationOn($employee, $referenceMonth->copy()->endOfMonth());
    }

    /**
     * Which incentive scheme pays $employee's month (one of the
     * INCENTIVE_SCHEME_* constants): the Team Leader revenue share, the
     * Manager PPP multiplier (Managers and Cluster Managers), or the flat
     * caller slab for everyone else. The one place a designation is mapped
     * to a scheme — every incentive figure and explanation goes through it.
     */
    public function incentiveSchemeFor(Employee $employee, ?Carbon $referenceMonth = null): string
    {
        $designation = $this->designationFor($employee, $referenceMonth);

        return match (true) {
            $designation === Employee::DESIGNATION_TEAM_LEADER => self::INCENTIVE_SCHEME_TEAM_LEADER,
            in_array($designation, self::MANAGER_SCHEME_DESIGNATIONS, true) => self::INCENTIVE_SCHEME_MANAGER,
            default => self::INCENTIVE_SCHEME_SLAB,
        };
    }

    /**
     * $employee's incentive for $referenceMonth under their scheme. The
     * flat caller slab is read off $countAchievement; the Team Leader and
     * Manager schemes work their own team figures out.
     */
    public function getIncentiveFor(Employee $employee, Carbon $referenceMonth, float $countAchievement): float
    {
        return (float) match ($this->incentiveSchemeFor($employee, $referenceMonth)) {
            self::INCENTIVE_SCHEME_TEAM_LEADER => $this->getTeamLeaderIncentiveBreakdown($employee, $referenceMonth)['incentive'],
            self::INCENTIVE_SCHEME_MANAGER => $this->getManagerIncentiveBreakdown($employee, $referenceMonth)['incentive'],
            default => $this->getIncentive($countAchievement),
        };
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

    /**
     * A caller's TEAM target for the month — what they bring to their
     * bosses: their category amount once on the rolls TargetLedger::
     * MIN_TARGET_DAYS days, else nothing. Not the figure on their own
     * screen (getTarget()), which ignores joining and leaving.
     */
    public function getHierarchyCallerTarget(Employee $employee, ?Carbon $referenceMonth = null): float
    {
        // See getTarget() above — target boundary logic must anchor to the
        // real requested month, never the achievement-driven fallback.
        $referenceMonth ??= SelectedMonth::current();

        return $this->callerTeamTargetForMonth($employee, $referenceMonth);
    }

    /**
     * getHierarchyCallerTarget() summed over the calendar months
     * [$start, $end] touches.
     */
    public function getHierarchyCallerTargetForPeriod(Employee $employee, Carbon $start, Carbon $end): float
    {
        $total = 0.0;

        foreach ($this->monthsOf($start, $end) as $month) {
            $total += $this->callerTeamTargetForMonth($employee, $month);
        }

        return round($total, 2);
    }

    /**
     * The ledger's figure for somebody it counts as a caller that month;
     * the same rule applied directly to anybody else.
     */
    private function callerTeamTargetForMonth(Employee $employee, Carbon $month): float
    {
        $ledger = TargetLedger::forMonth($month);

        return $ledger->designation($employee->id) === Employee::DESIGNATION_CALLER
            ? $ledger->target($employee->id)
            : TargetLedger::callerTeamTarget($employee, $month);
    }

    /**
     * Which days of the month $member counted in $boss's team, for a team
     * listing — or null when it was the whole month. With $withRule, also
     * says what that means for the figures.
     */
    public function reportingSpanNote(Employee $member, Employee $boss, ?Carbon $referenceMonth = null, bool $withRule = false): ?string
    {
        $referenceMonth ??= SelectedMonth::current();
        $timeline = ReportingTimeline::forMonth($referenceMonth);

        $spans = $timeline->branchSpans($boss->id, skipExitedLevels: true, includeBoss: false)[$member->id] ?? [];
        $days = ReportingTimeline::overlapDays($spans, $timeline->start(), $timeline->end());

        if ($days >= $timeline->days()) {
            return null;
        }

        if ($days === 0) {
            return 'Not in this team this month.';
        }

        $stretches = implode(', ', array_map(
            fn (array $span): string => $span[0]->format('d M').' – '.$span[1]->format('d M'),
            $spans,
        ));

        $note = "In this team {$stretches} ({$days} of {$timeline->days()} days).";

        if ($withRule) {
            $note .= ' Only cases disbursed on those days count here. '.$this->moverTargetRule($member, $boss, TargetLedger::forMonth($referenceMonth));
        }

        return $note;
    }

    /**
     * What somebody who moved into or out of a team brings to it — the
     * wording of TargetLedger's split rule.
     */
    private function moverTargetRule(Employee $member, Employee $boss, TargetLedger $ledger): string
    {
        $target = $ledger->target($member->id);
        $counted = $ledger->contribution($member->id, $boss->id);

        if ($target <= 0) {
            return 'No target counts for them this month.';
        }

        if ($counted <= 0) {
            return 'Under '.TargetLedger::MIN_TARGET_DAYS.' days here, so their target of '.indianAmount($target)
                .' counts with the team(s) where they spent '.TargetLedger::MIN_TARGET_DAYS.' days or more.';
        }

        if ($counted >= $target) {
            return 'Their full target of '.indianAmount($target).' counts here.';
        }

        return 'Their target of '.indianAmount($target).' is split by days between the teams they sat in, so '
            .indianAmount($counted).' counts here.';
    }

    /**
     * A one-line explanation of a caller's team target, for the screens a
     * Team Leader reads it on — or null when the figure is the plain
     * full-month category target and needs no explaining.
     *
     * This exists so the wording of the rule lives next to it; see
     * TargetLedger.
     */
    public function hierarchyCallerTargetNote(Employee $employee, ?Carbon $referenceMonth = null): ?string
    {
        $referenceMonth ??= SelectedMonth::current();
        $ledger = TargetLedger::forMonth($referenceMonth);
        $window = $ledger->window($employee->id);
        $minimum = TargetLedger::MIN_TARGET_DAYS;

        if ($window === null) {
            return 'Not on the rolls this month, so no target is counted.';
        }

        $notes = [];

        if ($ledger->hasMissingCategory($employee->id)) {
            $notes[] = 'No Target Category with an amount — set one on the employee; nothing counts for the days without it.';
        }

        if (! $window['is_partial']) {
            $notes[] = $this->categoryChangeNote($employee, $referenceMonth);

            return implode(' ', array_filter($notes)) ?: null;
        }

        $days = $window['days'];
        $span = $window['start']->format('d M').' to '.$window['end']->format('d M');

        $notes[] = match (true) {
            $days < $minimum => "Only {$days} day(s) on the rolls this month ({$span}) — under the {$minimum}-day minimum, so nothing counts for the team.",
            $window['is_projected'] => "{$days} days on the rolls this month ({$span}), counted on the basis that they stay to month-end, so their full category target counts. "
                ."If they leave before completing {$minimum} days, this target drops to zero automatically.",
            default => "{$days} days on the rolls this month ({$span}) — at or above the {$minimum}-day minimum, so their full category target counts.",
        };

        return implode(' ', $notes);
    }

    /**
     * How a full month's target was split when the target category changed
     * inside it, or null when it did not.
     */
    public function categoryChangeNote(Employee $employee, ?Carbon $referenceMonth = null): ?string
    {
        $referenceMonth ??= SelectedMonth::current();

        $spans = TargetCategoryTimeline::spans(
            $employee,
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        );

        if (count($spans) <= 1) {
            return null;
        }

        return 'Target category changed this month, so the target is split by days: '
            .collect($spans)
                ->map(fn (array $span): string => (TargetCategory::labelFor($span['category']) ?? 'no category')
                    .' ('.indianCurrencyFormat(TargetCategory::targetAmountFor($span['category'], $referenceMonth) ?? 0).') for '
                    .$span['days'].' day(s) from '.$span['start']->format('d M'))
                ->join(', ', ' and ')
            .'.';
    }
}
