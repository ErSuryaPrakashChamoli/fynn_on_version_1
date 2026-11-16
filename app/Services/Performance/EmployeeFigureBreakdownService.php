<?php

namespace App\Services\Performance;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\TargetCategory;
use App\Services\AchievementCalculatorService;
use App\Support\EmployeeLookupPeriod;
use App\Support\ReportingTimeline;
use App\Support\TargetCategoryTimeline;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures on the Employee Lookup page, and "how was this figure
 * made?" for its target, achievement and incentive cards — for whatever
 * period the page is analysing (EmployeeLookupPeriod).
 *
 * Every number comes from AchievementCalculatorService's own public
 * methods — the month target, the per-caller target shares, the
 * disbursed-case scope, the per-case achievement formula, the incentive
 * breakdowns — so an explanation can never disagree with the card it
 * explains. Targets and incentives are monthly by nature, so a longer
 * period adds them up month by month; achievement covers the exact dates.
 *
 * Every row naming a person carries `active` (on the rolls on at least one
 * day of the period) and `moved` (in this team for only part of the time
 * they were on the rolls), so the page can hide inactive people by default
 * and flag transfers in and out.
 *
 * The only arithmetic repeated here is the display of the Team Leader
 * revenue-share steps, whose result the service returns;
 * tests/Feature/EmployeeFigureBreakdownTest.php checks they agree.
 */
class EmployeeFigureBreakdownService
{
    /**
     * Disbursed cases listed in an achievement breakdown; the totals always
     * cover every case.
     */
    public const MAX_CASE_ROWS = 250;

    public function __construct(
        private readonly AchievementCalculatorService $calculator,
    ) {}

    /**
     * Target, achievement, deductions and incentive for the period: the
     * figures on the page's cards.
     *
     * @return array<string, mixed>
     */
    public function summary(Employee $employee, EmployeeLookupPeriod $period): array
    {
        $target = 0.0;
        $incentive = 0.0;

        foreach ($period->months() as $month) {
            $target += $this->calculator->getTarget($employee, $month);
            $incentive += $this->monthIncentive($employee, $month)['incentive'];
        }

        $totals = $this->calculator->computeAchievementTotals($this->disbursed($employee, $period->start, $period->end));
        $net = $totals['count_achievement'];

        return [
            ...$totals,
            'target' => $target,
            'target_category' => TargetCategoryTimeline::categoryOn($employee, self::categoryDay($period)),
            'percentage' => $this->calculator->percentageFromAmounts($net, $target),
            'deductions' => $totals['cashback'] + $totals['subvention'] + $totals['docking'],
            'remaining' => max($target - $net, 0),
            'incentive' => $incentive,
            'next_slab' => $period->isSingleMonth() && $employee->designation === Employee::DESIGNATION_CALLER
                ? $this->calculator->getNextIncentiveSlab($net)
                : null,
        ];
    }

    /**
     * How the period's target is built: month by month, and per caller
     * (with each caller's share summed over the months they counted).
     *
     * @return array<string, mixed>
     */
    public function target(Employee $employee, EmployeeLookupPeriod $period): array
    {
        $category = TargetCategoryTimeline::categoryOn($employee, self::categoryDay($period));

        $result = [
            'kind' => 'none',
            'total' => 0.0,
            'category' => TargetCategory::labelFor($category),
            'category_amount' => TargetCategory::targetAmountFor($category),
            'category_notes' => [],
            'months' => [],
            'callers' => [],
            'callers_total' => 0.0,
            'top_up' => 0.0,
            'team_leaders' => [],
        ];

        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            foreach ($period->months() as $month) {
                $result['months'][] = ['month' => $month, 'callers' => 0.0, 'top_up' => 0.0, 'total' => $this->calculator->getTarget($employee, $month)];

                if (($note = $this->calculator->categoryChangeNote($employee, $month)) !== null) {
                    $result['category_notes'][] = $month->format('M Y').': '.$note;
                }
            }

            return [...$result, 'kind' => 'caller', 'total' => array_sum(array_column($result['months'], 'total'))];
        }

        $isCompany = $employee->designation === Employee::DESIGNATION_ADMIN;

        if (! $isCompany && Employee::designationRank($employee->designation) === 0) {
            return $result;
        }

        $shares = [];
        $monthsCounted = [];
        $teamLeaderMonths = [];

        foreach ($period->months() as $month) {
            $monthTotal = $this->calculator->getTarget($employee, $month);

            if ($isCompany) {
                $monthShares = Employee::query()
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->get()
                    ->mapWithKeys(fn (Employee $caller): array => [$caller->id => $this->calculator->getHierarchyCallerTarget($caller, $month)])
                    ->all();
            } else {
                $timeline = ReportingTimeline::forMonth($month);
                $monthShares = $this->calculator->callerTargetShares($employee, $timeline);

                $teamLeaderDays = $timeline->branchDays($employee->id, skipExitedLevels: true, includeBoss: true, onlyDesignation: Employee::DESIGNATION_TEAM_LEADER);
                $toppedUp = $this->calculator->understaffedTeamLeaderIds($employee, $timeline);

                foreach (array_keys($teamLeaderDays) as $teamLeaderId) {
                    $teamLeaderMonths[$teamLeaderId]['months'] = ($teamLeaderMonths[$teamLeaderId]['months'] ?? 0) + 1;

                    if (in_array($teamLeaderId, $toppedUp, true)) {
                        $teamLeaderMonths[$teamLeaderId]['understaffed'] = ($teamLeaderMonths[$teamLeaderId]['understaffed'] ?? 0) + 1;
                    }
                }
            }

            foreach ($monthShares as $callerId => $share) {
                $shares[$callerId] = ($shares[$callerId] ?? 0.0) + $share;
                $monthsCounted[$callerId] = ($monthsCounted[$callerId] ?? 0) + ($share > 0 ? 1 : 0);
            }

            $callersTotal = array_sum($monthShares);

            $result['months'][] = [
                'month' => $month,
                'callers' => $callersTotal,
                'top_up' => max($monthTotal - $callersTotal, 0.0),
                'total' => $monthTotal,
            ];
        }

        $spans = $isCompany ? [] : $this->teamSpans($employee, $period, Employee::DESIGNATION_CALLER);
        $singleMonth = $period->isSingleMonth();

        $callers = Employee::query()
            ->whereIn('id', array_keys($shares))
            ->orderBy('emp_name')
            ->get()
            ->map(function (Employee $caller) use ($employee, $period, $shares, $monthsCounted, $spans, $singleMonth, $isCompany): array {
                $person = $this->person($caller, $period, $isCompany ? null : ($spans[$caller->id] ?? []));

                $note = $singleMonth
                    ? implode(' ', array_filter([
                        $this->calculator->hierarchyCallerTargetNote($caller, $period->start),
                        $isCompany ? null : $this->calculator->reportingSpanNote($caller, $employee, $period->start, withRule: true),
                    ]))
                    : implode(' ', array_filter([
                        'Counted in '.($monthsCounted[$caller->id] ?? 0).' of '.count($period->months()).' months.',
                        $person['note'],
                    ]));

                return [
                    ...$person,
                    'category' => TargetCategory::labelFor(TargetCategoryTimeline::categoryOn($caller, self::categoryDay($period))),
                    'share' => $shares[$caller->id] ?? 0.0,
                    'note' => $note ?: ($person['active'] ? 'On the rolls and in this team for the whole period.' : null),
                ];
            })
            ->all();

        $teamLeaderSpans = $isCompany ? [] : $this->teamSpans($employee, $period, Employee::DESIGNATION_TEAM_LEADER);

        $teamLeaders = Employee::query()
            ->whereIn('id', array_keys($teamLeaderMonths))
            ->orderBy('emp_name')
            ->get()
            ->map(fn (Employee $teamLeader): array => [
                ...$this->person($teamLeader, $period, $teamLeaderSpans[$teamLeader->id] ?? []),
                'callers' => $singleMonth
                    ? count(ReportingTimeline::forMonth($period->start)->headcountCallerIds($teamLeader->id, directOnly: true))
                    : null,
                'months_in_team' => $teamLeaderMonths[$teamLeader->id]['months'] ?? 0,
                'months_understaffed' => $teamLeaderMonths[$teamLeader->id]['understaffed'] ?? 0,
            ])
            ->all();

        $total = array_sum(array_column($result['months'], 'total'));
        $callersTotal = array_sum(array_column($result['months'], 'callers'));

        return [
            ...$result,
            'kind' => $isCompany ? 'company' : 'team',
            'total' => $total,
            'callers' => $callers,
            'callers_total' => $callersTotal,
            'top_up' => max($total - $callersTotal, 0.0),
            'team_leaders' => $teamLeaders,
        ];
    }

    /**
     * Every case disbursed in the period that counts for the employee, each
     * with its own gross amount, deductions and net contribution — worked
     * out by the same per-case formula as the totals — plus the same
     * figures per team member.
     *
     * @return array<string, mixed>
     */
    public function achievement(Employee $employee, EmployeeLookupPeriod $period, int $limit = self::MAX_CASE_ROWS): array
    {
        $scope = fn (): Builder => $this->disbursed($employee, $period->start, $period->end);

        $customers = $scope()
            ->with('employee')
            ->orderByDesc('customers.disbursal_date')
            ->orderByDesc('customers.id')
            ->limit($limit)
            ->get(['customers.*']);

        $rows = $customers->map(function (Customer $customer) use ($period): array {
            $figures = $this->caseFigures($customer->id);

            return [
                ...$figures,
                'id' => $customer->id,
                'application_no' => $customer->application_no,
                'customer_name' => (string) $customer->customer_name,
                'owner' => $customer->employee?->emp_name,
                'owner_emp_id' => $customer->employee?->emp_id,
                'active' => $customer->employee === null || $period->wasActive($customer->employee),
                'bank' => $customer->sanctioned_bank,
                'disbursal_date' => $customer->disbursal_date ? Carbon::parse($customer->disbursal_date)->format('d M Y') : null,
            ];
        })->all();

        return [
            'totals' => $this->calculator->computeAchievementTotals($scope()),
            'count' => $scope()->count(),
            'rows' => $rows,
            'members' => $this->members($employee, $period, $scope),
        ];
    }

    /**
     * How the period's incentive was reached: in full detail for a single
     * month, month by month for anything longer.
     *
     * @return array<string, mixed>
     */
    public function incentive(Employee $employee, EmployeeLookupPeriod $period): array
    {
        if (! $period->isSingleMonth()) {
            $months = array_map(fn (Carbon $month): array => [
                'month' => $month,
                ...$this->monthIncentive($employee, $month),
            ], $period->months());

            return [
                'kind' => 'months',
                'scheme' => $this->schemeFor($employee, $period->end->copy()),
                'months' => $months,
                'incentive' => array_sum(array_column($months, 'incentive')),
            ];
        }

        $month = $period->start->copy();

        if (! $this->monthHasDisbursals($month)) {
            return ['kind' => 'no_data', 'incentive' => 0.0];
        }

        // The scheme of the designation held that month, not today's.
        $designation = $this->calculator->designationFor($employee, $month);

        if ($designation === Employee::DESIGNATION_TEAM_LEADER) {
            $breakdown = $this->calculator->getTeamLeaderIncentiveBreakdown($employee, $month);

            return [
                'kind' => 'team_leader',
                ...$breakdown,
                'steps' => $breakdown['meets_gate'] ? $this->teamLeaderSteps($breakdown) : null,
                'callers' => $this->teamLeaderCallers($employee, $period),
            ];
        }

        if ($designation === Employee::DESIGNATION_MANAGER) {
            return [
                'kind' => 'manager',
                ...$this->calculator->getManagerIncentiveBreakdown($employee, $month),
                'bands' => array_map(
                    fn (int $from): array => ['from' => (float) $from, 'multiplier' => $this->calculator->getPPPMultiplier($from)],
                    [2300000, 2500000, 2700000, 2900000, 3100000],
                ),
                'callers' => $this->managerCallers($employee, $period),
            ];
        }

        $net = $this->monthIncentive($employee, $month)['net'];
        $reached = null;

        foreach (array_keys($this->calculator->getIncentiveSlabs()) as $slabTarget) {
            if ($net >= $slabTarget) {
                $reached = (float) $slabTarget;
            }
        }

        return [
            'kind' => 'slab',
            'net' => $net,
            'incentive' => $this->calculator->getIncentive($net),
            'slabs' => $this->calculator->getIncentiveSlabs(),
            'reached' => $reached,
            'next' => $this->calculator->getNextIncentiveSlab($net),
        ];
    }

    /**
     * The Team Leader revenue-share steps for a team that met its gate,
     * laid out for reading. The service returns only the result; these
     * steps reproduce it (asserted in the feature test).
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, float|bool>
     */
    public function teamLeaderSteps(array $breakdown): array
    {
        $callerCount = (int) $breakdown['caller_count'];
        $revenue = (float) $breakdown['team_achievement'] * 0.02;
        $targetRevenue = (float) $breakdown['team_target'] * 0.02;
        $callerCost = $callerCount * 30000;
        $grossRevenue = max(0, $revenue - $callerCost);
        $grossRate = match (true) {
            $callerCount === 3 => 0.06,
            $callerCount === 4 => 0.07,
            $callerCount >= 5 => 0.08,
            default => 0.0,
        };
        $extraRevenue = max(0, $revenue - $targetRevenue);
        $base = ($grossRevenue * $grossRate) + ($extraRevenue * 0.05);
        $bonus = $callerCount >= 3 && $breakdown['all_callers_achieved'];

        return [
            'revenue' => $revenue,
            'caller_cost' => (float) $callerCost,
            'gross_revenue' => (float) $grossRevenue,
            'gross_rate' => $grossRate,
            'target_revenue' => $targetRevenue,
            'extra_revenue' => (float) $extraRevenue,
            'base' => $base,
            'bonus' => $bonus,
            'result' => (float) round(max($bonus ? $base * 1.10 : $base, 0)),
        ];
    }

    /**
     * One exact calendar month's net achievement and incentive. A month in
     * which nothing has been disbursed anywhere yet earns nothing: the
     * service's month resolver would otherwise silently swap in the month
     * before, which a period total must never count twice.
     *
     * @return array{net: float, incentive: float}
     */
    public function monthIncentive(Employee $employee, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $net = $this->calculator->computeAchievementTotals($this->disbursed($employee, $start, $end))['count_achievement'];

        if (! $this->monthHasDisbursals($start)) {
            return ['net' => $net, 'incentive' => 0.0];
        }

        $incentive = match ($this->calculator->designationFor($employee, $start)) {
            Employee::DESIGNATION_TEAM_LEADER => $this->calculator->getTeamLeaderIncentiveBreakdown($employee, $start)['incentive'],
            Employee::DESIGNATION_MANAGER => $this->calculator->getManagerIncentiveBreakdown($employee, $start)['incentive'],
            default => $this->calculator->getIncentive($net),
        };

        return ['net' => $net, 'incentive' => (float) $incentive];
    }

    /**
     * The incentive scheme of the designation held in $month.
     */
    private function schemeFor(Employee $employee, Carbon $month): string
    {
        return match ($this->calculator->designationFor($employee, $month)) {
            Employee::DESIGNATION_TEAM_LEADER => 'team_leader',
            Employee::DESIGNATION_MANAGER => 'manager',
            default => 'slab',
        };
    }

    private function monthHasDisbursals(Carbon $month): bool
    {
        return $this->calculator->resolveReferenceMonth($month->copy()->startOfMonth())->isSameMonth($month);
    }

    /**
     * The cases disbursed in [$start, $end] that count for the employee.
     */
    private function disbursed(Employee $employee, Carbon $start, Carbon $end): Builder
    {
        return $this->calculator->disbursedCustomersFor($employee, $start, $end);
    }

    /**
     * @return array{actual: float, cashback: float, subvention: float, docking: float, deduction_rate: ?int, deduction: float, net: float}
     */
    private function caseFigures(int $customerId): array
    {
        $figures = $this->calculator->computeAchievementTotals(Customer::query()->whereKey($customerId));

        $deductible = $figures['cashback'] + $figures['subvention'] + $figures['docking'];
        $deduction = $figures['actual'] - $figures['count_achievement'];

        return [
            'actual' => $figures['actual'],
            'cashback' => $figures['cashback'],
            'subvention' => $figures['subvention'],
            'docking' => $figures['docking'],
            'deduction_rate' => $deductible > 0 ? (int) round($deduction / $deductible) : null,
            'deduction' => $deduction,
            'net' => $figures['count_achievement'],
        ];
    }

    /**
     * The achievement per person: everyone in the team at any point of the
     * period (so a transfer in or out shows up), plus anyone else whose
     * case counted.
     *
     * @param  callable(): Builder  $scope
     * @return list<array<string, mixed>>
     */
    private function members(Employee $employee, EmployeeLookupPeriod $period, callable $scope): array
    {
        $isCompany = $employee->designation === Employee::DESIGNATION_ADMIN;
        $spans = $isCompany ? [] : $this->teamSpans($employee, $period);

        $ownerIds = $scope()->distinct()->pluck('customers.employee_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $memberIds = array_values(array_unique([...array_keys($spans), ...$ownerIds]));

        return Employee::query()
            ->whereIn('id', $memberIds)
            ->orderBy('emp_name')
            ->get()
            ->map(function (Employee $member) use ($period, $scope, $spans, $ownerIds, $isCompany): array {
                $figures = in_array($member->id, $ownerIds, true)
                    ? $this->calculator->computeAchievementTotals($scope()->where('customers.employee_id', $member->id))
                    : ['actual' => 0.0, 'count_achievement' => 0.0];

                return [
                    ...$this->person($member, $period, $isCompany ? null : ($spans[$member->id] ?? [])),
                    'cases' => in_array($member->id, $ownerIds, true) ? $scope()->where('customers.employee_id', $member->id)->count() : 0,
                    'actual' => (float) $figures['actual'],
                    'net' => (float) $figures['count_achievement'],
                    'deduction' => (float) $figures['actual'] - (float) $figures['count_achievement'],
                ];
            })
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    /**
     * A Team Leader's headcount callers for the month: their share of the
     * team target, what they achieved while in the team, and whether that
     * met it — the "every caller met their target" bonus check.
     *
     * @return list<array<string, mixed>>
     */
    private function teamLeaderCallers(Employee $teamLeader, EmployeeLookupPeriod $period): array
    {
        $timeline = ReportingTimeline::forMonth($period->start);
        $shares = $this->calculator->callerTargetShares($teamLeader, $timeline);
        $spans = $this->teamSpans($teamLeader, $period, Employee::DESIGNATION_CALLER);

        return Employee::query()
            ->whereIn('id', $timeline->headcountCallerIds($teamLeader->id))
            ->orderBy('emp_name')
            ->get()
            ->map(function (Employee $caller) use ($teamLeader, $period, $shares, $spans): array {
                $achieved = $this->calculator->computeAchievementTotals(
                    $this->calculator->disbursedCustomersFor($caller, $period->start, $period->end, within: $teamLeader)
                )['count_achievement'];

                return [
                    ...$this->person($caller, $period, $spans[$caller->id] ?? []),
                    'target' => $shares[$caller->id] ?? 0.0,
                    'achieved' => $achieved,
                    'met' => $achieved >= ($shares[$caller->id] ?? 0.0),
                ];
            })
            ->all();
    }

    /**
     * A Manager's headcount callers for the month, and which of them carry
     * a target (the PPP divisor).
     *
     * @return list<array<string, mixed>>
     */
    private function managerCallers(Employee $manager, EmployeeLookupPeriod $period): array
    {
        $spans = $this->teamSpans($manager, $period, Employee::DESIGNATION_CALLER);

        return Employee::query()
            ->whereIn('id', ReportingTimeline::forMonth($period->start)->headcountCallerIds($manager->id))
            ->orderBy('emp_name')
            ->get()
            ->map(fn (Employee $caller): array => [
                ...$this->person($caller, $period, $spans[$caller->id] ?? []),
                'target' => $this->calculator->getHierarchyCallerTarget($caller, $period->start),
            ])
            ->map(fn (array $row): array => [...$row, 'eligible' => $row['target'] > 0])
            ->all();
    }

    /**
     * For everyone in $boss's team at any point of the period, the
     * stretches they were in it.
     *
     * @return array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    private function teamSpans(Employee $boss, EmployeeLookupPeriod $period, ?int $onlyDesignation = null): array
    {
        return ReportingTimeline::between($period->start, $period->end)->branchSpans(
            $boss->id,
            skipExitedLevels: true,
            includeBoss: true,
            onlyDesignation: $onlyDesignation,
        );
    }

    /**
     * Who someone is, whether they were on the rolls during the period, and
     * — given the stretches they spent in the team — whether they were in
     * it for only part of that time (moved in or out).
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>|null  $spans  null when team membership does not apply
     * @return array{id: int, name: string, emp_id: ?string, designation: string, active: bool, exited: bool, moved: bool, note: ?string}
     */
    private function person(Employee $employee, EmployeeLookupPeriod $period, ?array $spans): array
    {
        $window = $period->activeWindow($employee);
        $moved = false;
        $notes = [];

        if ($window === null) {
            $notes[] = 'Not on the rolls in this period.';
        } elseif ($spans !== null && $spans !== []) {
            $activeDays = ReportingTimeline::dayCount($window['start'], $window['end']);
            $teamDays = ReportingTimeline::overlapDays($spans, $window['start'], $window['end']);

            if ($teamDays < $activeDays) {
                $moved = true;
                $notes[] = 'In this team '.implode(', ', array_map(
                    fn (array $span): string => $span[0]->format('d M Y').' – '.$span[1]->format('d M Y'),
                    $spans,
                )).'.';
            }
        }

        if ($window !== null && $window['start']->gt($period->start)) {
            $notes[] = 'Joined '.$window['start']->format('d M Y').'.';
        }

        if ($window !== null && $window['end']->lt($period->end->copy()->startOfDay())) {
            $notes[] = 'Left '.$window['end']->format('d M Y').'.';
        }

        return [
            'id' => $employee->id,
            'name' => (string) $employee->emp_name,
            'emp_id' => $employee->emp_id,
            'designation' => Employee::designationOptions()[$employee->designation] ?? '—',
            'active' => $window !== null,
            'exited' => EmployeeLookupPeriod::isExited($employee),
            'moved' => $moved,
            'note' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    /**
     * The day whose target category a period is labelled with: its last
     * day, or today while the period is still running.
     */
    public static function categoryDay(EmployeeLookupPeriod $period): Carbon
    {
        return $period->end->copy()->min(today());
    }
}
