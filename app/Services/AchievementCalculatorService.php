<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\TargetCategory;
use App\Support\HierarchyHelper;
use App\Support\OnRollsWindow;
use App\Support\ReportingTimeline;
use App\Support\SelectedMonth;
use App\Support\TargetCategoryTimeline;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
     * Calendar days a caller must be on the rolls within a month before
     * their target is counted into the hierarchy above them.
     */
    private const MIN_TARGET_DAYS = 10;

    /**
     * The flat target a caller carries for a month they were only on the
     * rolls for part of — a joiner, a leaver, or both.
     */
    private const PARTIAL_MONTH_TARGET = 1500000.0;

    /**
     * A Team Leader with fewer callers than this adds UNDERSTAFFED_TEAM_TOP_UP
     * to every target that counts them.
     */
    private const UNDERSTAFFED_TEAM_CALLERS = 3;

    public const UNDERSTAFFED_TEAM_TOP_UP = 3000000.0;

    /**
     * Fallback when employees.category is blank or names a category with
     * no target amount (e.g. 'team_leader').
     */
    private const DEFAULT_CATEGORY_TARGET = 2500000.0;

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
     * Single authoritative target engine, for an arbitrary period.
     *
     * getTarget() (current month) is a thin wrapper around this method —
     * both current-month and historical-period target calculations go
     * through the exact same business rules, month by month.
     *
     * A boss's month is counted on the reporting tree of each DAY of that
     * month (ReportingTimeline), never today's tree: a caller counts in
     * every team they spent ten days or more in (callerTargetShares()), and
     * the ₹30L understaffed-team top-up of a Team Leader likewise follows
     * the days that Team Leader sat in it. With no reporting change in the
     * month this is exactly the whole-month figure it always was.
     *
     * Each month is counted as the position held in it (designationFor(),
     * the one held on its last day), so after a promotion or demotion the
     * months before its effective date keep the old position: a caller
     * promoted to Team Leader on 1 Nov carries their own caller target for
     * October and their team's target from November.
     *
     * $within narrows the figure to the days $employee sat in $within's
     * branch — what $employee's team contributes to that boss.
     */
    public function getTargetForPeriod(Employee $employee, Carbon $start, Carbon $end, ?Employee $within = null): float
    {
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

        $target = 0.0;

        foreach ($this->monthsOf($start, $end) as $month) {
            $designation = $this->designationFor($employee, $month);

            /*
        |--------------------------------------------------------------------------
        | Caller
        |--------------------------------------------------------------------------
        |
        | Their own target is the flat category value. Seen from inside a
        | boss's team ($within), it is what their month counts there.
        |
        */

            if ($designation === Employee::DESIGNATION_CALLER) {
                $target += $within === null
                    ? $this->getCallerTarget($employee, $month->copy()->endOfMonth())
                    : $this->callerTargetShares($within, ReportingTimeline::forMonth($month))[$employee->id] ?? 0.0;

                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Team Leader, Manager, Cluster Manager, Business Head
        |--------------------------------------------------------------------------
        |
        | Every caller the branch counts, plus the ₹30L understaffed-team
        | top-up for each Team Leader in it — themselves included — with
        | fewer than three callers. Levels may be skipped: a Team Leader
        | reporting straight to a Cluster Manager carries their callers and
        | their top-up into that Cluster Manager's target, and never into a
        | Manager's.
        |
        */

            if (Employee::designationRank($designation) > 0) {
                $timeline = ReportingTimeline::forMonth($month);

                $target += array_sum($this->callerTargetShares($employee, $timeline, $within));
                $target += $this->understaffedTeamTopUps($employee, $timeline, $within);
            }
        }

        return $target;
    }

    /**
     * Each caller's month target as it counts for $boss.
     *
     * A caller in $boss's branch for every day they were on the rolls that
     * month carries their own month target (category, partial-month or nil
     * — see hierarchyCallerTargetForMonth()), counted 1st to month-end.
     *
     * A caller who moved into or out of the branch inside that window
     * carries that same full month target into it when they spent at least
     * MIN_TARGET_DAYS calendar days in it while on the rolls, otherwise
     * nothing (user decision 2026-11-24, replacing the flat part-month
     * target for movers of 2026-11-16). Each team judges its own days, so a
     * caller moved on the 2nd or the 16th counts their full target in both
     * teams, and one moved on the 26th only in the old one.
     *
     * @return array<int, float> caller id => target counted for $boss
     */
    public function callerTargetShares(Employee $boss, ReportingTimeline $timeline, ?Employee $within = null): array
    {
        $spans = $timeline->branchSpans(
            $boss->id,
            skipExitedLevels: true,
            includeBoss: true,
            withinId: $within?->id,
            onlyDesignation: Employee::DESIGNATION_CALLER,
        );

        if ($spans === []) {
            return [];
        }

        $monthStart = Carbon::parse($timeline->start()->toDateString());
        $monthEnd = Carbon::parse($timeline->end()->toDateString());
        $shares = [];

        foreach (Employee::query()->whereIn('id', array_keys($spans))->get() as $caller) {
            $window = $this->activeWindowForMonth($caller, $monthStart, $monthEnd);
            $target = $this->hierarchyCallerTargetForMonth($caller, $monthStart, $monthEnd);

            if ($window === null || $target <= 0 || $window['days'] <= 0) {
                $shares[$caller->id] = 0.0;

                continue;
            }

            $daysInBranch = ReportingTimeline::overlapDays($spans[$caller->id], $window['start'], $window['end']);

            if ($daysInBranch >= $window['days']) {
                $shares[$caller->id] = $target;

                continue;
            }

            $shares[$caller->id] = $daysInBranch >= self::MIN_TARGET_DAYS ? $target : 0.0;
        }

        return $shares;
    }

    /**
     * The ₹30L top-up for every Team Leader whose top-up counts in $boss's
     * branch that month — see understaffedTeamLeaderIds().
     */
    private function understaffedTeamTopUps(Employee $boss, ReportingTimeline $timeline, ?Employee $within = null): float
    {
        return count($this->understaffedTeamLeaderIds($boss, $timeline, $within)) * self::UNDERSTAFFED_TEAM_TOP_UP;
    }

    /**
     * Team Leaders in $boss's branch — $boss too, if they are one — who add
     * the full UNDERSTAFFED_TEAM_TOP_UP to it that month: their headcount
     * (ReportingTimeline::headcountCallerIds(), callers reporting straight
     * to them for ten days on the rolls) is under UNDERSTAFFED_TEAM_CALLERS,
     * and they themselves spent at least MIN_TARGET_DAYS days in the branch
     * while on the rolls.
     *
     * The same ten-day rule as a caller's target (user decision 2026-11-16,
     * replacing pro-rata by days): a Team Leader moved in on the 25th, or
     * who joined then, adds nothing; one there for ten days or more adds
     * the whole top-up, in each team that qualifies.
     *
     * @return list<int>
     */
    public function understaffedTeamLeaderIds(Employee $boss, ReportingTimeline $timeline, ?Employee $within = null): array
    {
        $teamLeaderDays = $timeline->daysOnTheRolls($timeline->branchSpans(
            $boss->id,
            skipExitedLevels: true,
            includeBoss: true,
            withinId: $within?->id,
            onlyDesignation: Employee::DESIGNATION_TEAM_LEADER,
        ));

        return array_keys(array_filter(
            $teamLeaderDays,
            fn (int $days, int $teamLeaderId): bool => $days >= self::MIN_TARGET_DAYS
                && count($timeline->headcountCallerIds($teamLeaderId, directOnly: true)) < self::UNDERSTAFFED_TEAM_CALLERS,
            ARRAY_FILTER_USE_BOTH,
        ));
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
    | the month is greater than zero. The target must be the same
    | reporting-date/exit-status aware one (getHierarchyCallerTarget) as the
    | rest of this service — getCallerTarget() only returns the caller's
    | static category value (or a 2,500,000 default) and is never zero, so
    | it would count a not-yet-eligible new joiner (<10 days into the month)
    | or an already-exited caller as "eligible", inflating the PPP
    | denominator.
    |
    */

        $referenceMonth ??= SelectedMonth::current();

        $callerIds = ReportingTimeline::forMonth($referenceMonth)->headcountCallerIds($manager->id);

        if ($callerIds === []) {
            return 0;
        }

        return Employee::whereIn('id', $callerIds)
            ->get()
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
        $monthStart = $referenceMonth->copy()->startOfMonth();
        $monthEnd = $referenceMonth->copy()->endOfMonth();

        // The month is read off the reporting tree of each of its days: a
        // caller is in this team's headcount if they spent at least
        // ReportingTimeline::MIN_HEADCOUNT_DAYS in it, the team target is
        // each caller's pro-rata share, and the achievement is the cases
        // disbursed while each caller was in the team.
        $timeline = ReportingTimeline::forMonth($referenceMonth);

        $callers = Employee::whereIn('id', $timeline->headcountCallerIds($teamLeader->id))->get();

        $callerCount = $callers->count();

        $eligibleCallers = $callers
            ->filter(fn (Employee $caller) => $this->getHierarchyCallerTarget($caller, $referenceMonth) > 0)
            ->count();

        $targetShares = $this->callerTargetShares($teamLeader, $timeline);

        $teamTarget = array_sum($targetShares);

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

    private function getCallerTarget(Employee $employee, Carbon $periodEnd): float
    {

        /*
    |--------------------------------------------------------------------------
    | CALLER OWN TARGET
    |--------------------------------------------------------------------------
    |
    | Caller target is ALWAYS based on assigned category — the one held in
    | the month the period ends in, split by days when it changed inside
    | that month (see categoryTarget()).
    |
    | DOJ, reporting_date, joining date and exit date do NOT affect
    | the caller's own target.
    |
    */

        return $this->categoryTarget($employee, $periodEnd->copy()->startOfMonth(), $periodEnd->copy()->endOfMonth());
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
     * The month is reduced to the window the caller is actually on the
     * rolls for, and the target follows from that window alone:
     *
     *   - on the rolls for the whole month  -> their category target;
     *   - a partial month (joined and/or left inside it) -> the flat
     *     partial-month target if the window spans at least
     *     MIN_TARGET_DAYS calendar days, otherwise nothing.
     *
     * The window's END is the key business rule (changed 2026-09-10, see
     * activeWindowForMonth()): for a caller who is still on the rolls it
     * runs to month-end, NOT to today.
     */
    private function hierarchyCallerTargetForMonth(Employee $employee, Carbon $monthStart, Carbon $monthEnd): float
    {
        $window = $this->activeWindowForMonth($employee, $monthStart, $monthEnd);

        if ($window === null) {
            return 0.0;
        }

        if (! $window['is_partial']) {
            return $this->categoryTarget($employee, $monthStart, $monthEnd);
        }

        return $window['days'] >= self::MIN_TARGET_DAYS
            ? self::PARTIAL_MONTH_TARGET
            : 0.0;
    }

    /**
     * The slice of [$monthStart, $monthEnd] a caller is on the rolls for —
     * see OnRollsWindow, which also feeds the headcount.
     *
     * @return array{start: Carbon, end: Carbon, days: int, is_partial: bool, is_projected: bool}|null
     */
    private function activeWindowForMonth(Employee $employee, Carbon $monthStart, Carbon $monthEnd): ?array
    {
        return OnRollsWindow::forMonth($employee, $monthStart, $monthEnd);
    }

    /**
     * The category target for the calendar month [$monthStart, $monthEnd],
     * from the category held on each day of it (TargetCategoryTimeline):
     * a change inside the month splits it by days (user decision
     * 2026-11-16) — the old amount for the days before the effective date,
     * the new one from it — and every other month keeps the one category
     * it was held in, so a change never restates a past month.
     */
    private function categoryTarget(Employee $employee, Carbon $monthStart, Carbon $monthEnd): float
    {
        $spans = TargetCategoryTimeline::spans($employee, $monthStart, $monthEnd);

        if (count($spans) <= 1) {
            return $this->categoryAmount($spans[0]['category'] ?? $employee->category);
        }

        $daysInMonth = (int) $monthStart->daysInMonth;
        $target = 0.0;

        foreach ($spans as $span) {
            $target += $this->categoryAmount($span['category']) * $span['days'] / $daysInMonth;
        }

        return round($target, 2);
    }

    private function categoryAmount(?string $category): float
    {
        return TargetCategory::targetAmountFor($category)
            ?? self::DEFAULT_CATEGORY_TARGET;
    }

    /**
     * Which days of the month $member counted in $boss's team, for a team
     * listing — or null when it was the whole month. With $withRule, also
     * says what that means for the figures.
     */
    public function reportingSpanNote(Employee $member, Employee $boss, ?Carbon $referenceMonth = null, bool $withRule = false): ?string
    {
        $timeline = ReportingTimeline::forMonth($referenceMonth ?? SelectedMonth::current());

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
            $note .= ' Only cases disbursed on those days count here.';

            if ($timeline->designation($member->id) === Employee::DESIGNATION_CALLER) {
                $note .= ' '.$this->moverTargetRule($member, $spans, $timeline);
            }
        }

        return $note;
    }

    /**
     * What a caller who moved into or out of a team brings to it — the
     * wording of callerTargetShares()'s rule: their full month target with
     * MIN_TARGET_DAYS or more days in the team while on the rolls, else
     * nothing.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $spans  the days they spent in the team
     */
    private function moverTargetRule(Employee $member, array $spans, ReportingTimeline $timeline): string
    {
        $monthStart = Carbon::parse($timeline->start()->toDateString());
        $monthEnd = Carbon::parse($timeline->end()->toDateString());
        $window = $this->activeWindowForMonth($member, $monthStart, $monthEnd);
        $daysOnTheRolls = $window === null ? 0 : ReportingTimeline::overlapDays($spans, $window['start'], $window['end']);

        if ($daysOnTheRolls < self::MIN_TARGET_DAYS) {
            return 'Under '.self::MIN_TARGET_DAYS.' days here while on the rolls, so no target counts.';
        }

        return 'With '.self::MIN_TARGET_DAYS.' or more days here, their full target of '
            .indianAmount($this->hierarchyCallerTargetForMonth($member, $monthStart, $monthEnd)).' counts.';
    }

    /**
     * A one-line explanation of a caller's hierarchy target, for the
     * screens a Team Leader reads it on — or null when the figure is the
     * plain full-month category target and needs no explaining.
     *
     * This exists so the wording of the projection lives in the same class
     * as the rule itself; see activeWindowForMonth().
     */
    public function hierarchyCallerTargetNote(Employee $employee, ?Carbon $referenceMonth = null): ?string
    {
        $referenceMonth ??= SelectedMonth::current();

        $window = $this->activeWindowForMonth(
            $employee,
            $referenceMonth->copy()->startOfMonth(),
            $referenceMonth->copy()->endOfMonth(),
        );

        if ($window === null) {
            return 'Not on the rolls this month, so no target is counted.';
        }

        if (! $window['is_partial']) {
            return $this->categoryChangeNote($employee, $referenceMonth);
        }

        $days = $window['days'];
        $span = $window['start']->format('d M').' to '.$window['end']->format('d M');

        if ($days < self::MIN_TARGET_DAYS) {
            return "Only {$days} day(s) on the rolls this month ({$span}) — under the "
                .self::MIN_TARGET_DAYS.'-day minimum, so no target is counted.';
        }

        if ($window['is_projected']) {
            return "{$days} days on the rolls this month ({$span}), counted on the basis that "
                .'they stay to month-end. If they leave before completing '
                .self::MIN_TARGET_DAYS.' days, this target drops to zero automatically.';
        }

        return "{$days} days on the rolls this month ({$span}) — at or above the "
            .self::MIN_TARGET_DAYS.'-day minimum.';
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
                    .' ('.indianCurrencyFormat($this->categoryAmount($span['category'])).') for '
                    .$span['days'].' day(s) from '.$span['start']->format('d M'))
                ->join(', ', ' and ')
            .'.';
    }
}
