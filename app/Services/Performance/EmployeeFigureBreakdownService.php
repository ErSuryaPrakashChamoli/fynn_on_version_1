<?php

namespace App\Services\Performance;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\TargetCategory;
use App\Services\AchievementCalculatorService;
use App\Support\EmployeeLookupPeriod;
use App\Support\ReportingTimeline;
use App\Support\ReportingTree;
use App\Support\TargetCategoryTimeline;
use App\Support\TargetLedger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

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

        // The position held at the end of the period: after a promotion the
        // months before it are still listed, each at its own figure.
        if (ReportingTimeline::designationOn($employee, self::categoryDay($period)) === Employee::DESIGNATION_CALLER) {
            foreach ($period->months() as $month) {
                $result['months'][] = ['month' => $month, 'callers' => 0.0, 'top_up' => 0.0, 'total' => $this->calculator->getTarget($employee, $month)];

                if (($note = $this->calculator->categoryChangeNote($employee, $month)) !== null) {
                    $result['category_notes'][] = $month->format('M Y').': '.$note;
                }
            }

            $teamTotal = 0.0;
            $missingCategory = false;

            foreach ($period->months() as $month) {
                $ledger = TargetLedger::forMonth($month);
                $teamTotal += $ledger->target($employee->id);
                $missingCategory = $missingCategory || $ledger->hasMissingCategory($employee->id);
            }

            return [
                ...$result,
                'kind' => 'caller',
                'total' => array_sum(array_column($result['months'], 'total')),
                // What the caller brings to their bosses: nothing for a month
                // with under TargetLedger::MIN_TARGET_DAYS days on the rolls.
                'team_total' => $teamTotal,
                'missing_category' => $missingCategory,
            ];
        }

        $isCompany = $employee->designation === Employee::DESIGNATION_ADMIN;

        if (! $isCompany && Employee::designationRank($employee->designation) === 0) {
            return $result;
        }

        $bossId = $isCompany ? TargetLedger::COMPANY : $employee->id;
        $shares = [];
        $monthsCounted = [];
        $teamLeaderMonths = [];

        foreach ($period->months() as $month) {
            $ledger = TargetLedger::forMonth($month);
            $monthTotal = $isCompany ? $ledger->companyTarget() : $this->calculator->getTarget($employee, $month);
            $monthShares = $ledger->contributions($bossId, Employee::DESIGNATION_CALLER);

            if (! $isCompany) {
                $floors = $ledger->floorContributions($bossId);
                $teamLeaderIds = $ledger->memberIds($bossId, Employee::DESIGNATION_TEAM_LEADER);

                if ($ledger->designation($bossId) === Employee::DESIGNATION_TEAM_LEADER) {
                    $teamLeaderIds[] = $bossId;
                }

                foreach ($teamLeaderIds as $teamLeaderId) {
                    $teamLeaderMonths[$teamLeaderId]['months'] = ($teamLeaderMonths[$teamLeaderId]['months'] ?? 0) + 1;

                    if (($floors[$teamLeaderId] ?? 0.0) > 0) {
                        $teamLeaderMonths[$teamLeaderId]['floored'] = ($teamLeaderMonths[$teamLeaderId]['floored'] ?? 0) + 1;
                        $teamLeaderMonths[$teamLeaderId]['floor'] = ($teamLeaderMonths[$teamLeaderId]['floor'] ?? 0.0) + $floors[$teamLeaderId];
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

        $callerModels = Employee::query()
            ->whereIn('id', array_keys($shares))
            ->orderBy('emp_name')
            ->get();

        $callers = $callerModels
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
            // Only the period's own callers: someone with no day on the rolls in it can never bring a target.
            ->filter(fn (array $caller): bool => $caller['active'] || $caller['share'] > 0)
            ->values()
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
                'months_floored' => $teamLeaderMonths[$teamLeader->id]['floored'] ?? 0,
                'floor' => $teamLeaderMonths[$teamLeader->id]['floor'] ?? 0.0,
                'team_sum' => $singleMonth ? TargetLedger::forMonth($period->start)->teamSum($teamLeader->id) : null,
            ])
            ->all();

        $total = array_sum(array_column($result['months'], 'total'));
        $callersTotal = array_sum(array_column($result['months'], 'callers'));
        $groupedByTeam = ! $isCompany
            && Employee::designationRank($employee->designation) > Employee::designationRank(Employee::DESIGNATION_TEAM_LEADER);

        return [
            ...$result,
            'kind' => $isCompany ? 'company' : 'team',
            'total' => $total,
            'callers' => $callers,
            'callers_total' => $callersTotal,
            'top_up' => max($total - $callersTotal, 0.0),
            'team_leaders' => $teamLeaders,
            'period_days' => ReportingTimeline::dayCount($period->start, $period->end),
            'grouped_by_team' => $groupedByTeam,
            'caller_groups' => match (true) {
                $callers === [] => [],
                $groupedByTeam => $this->callerGroups($callers, $this->mainTeamLeaderIds($employee, $period, $callerModels), $teamLeaders, $period),
                default => [['team_leader' => null, ...$this->groupFigures($callers)]],
            },
        ];
    }

    /**
     * The callers split by the Team Leader they sat under, each team with
     * the part of the callers' targets it brings and what the Team Leader
     * minimum (TargetLedger::TEAM_LEADER_MIN_TARGET) added; callers with no
     * Team Leader in between come last.
     *
     * @param  list<array<string, mixed>>  $callers
     * @param  array<int, ?int>  $mainTeamLeaderIds  caller id => Team Leader id
     * @param  list<array<string, mixed>>  $teamLeaders
     * @return list<array{team_leader: ?array<string, mixed>, callers: list<array<string, mixed>>, total: float, counted: int, on_rolls: int, left: int, top_up: float}>
     */
    private function callerGroups(array $callers, array $mainTeamLeaderIds, array $teamLeaders, EmployeeLookupPeriod $period): array
    {
        $teamLeaderRows = collect($teamLeaders)->keyBy('id');

        $missingIds = array_diff(array_filter($mainTeamLeaderIds), $teamLeaderRows->keys()->all());

        Employee::query()
            ->whereIn('id', $missingIds)
            ->get()
            ->each(function (Employee $teamLeader) use ($teamLeaderRows, $period): void {
                $teamLeaderRows->put($teamLeader->id, [...$this->person($teamLeader, $period, null), 'months_floored' => 0, 'floor' => 0.0]);
            });

        return collect($callers)
            ->groupBy(fn (array $caller): int => $mainTeamLeaderIds[$caller['id']] ?? 0)
            ->map(function ($groupCallers, int $teamLeaderId) use ($teamLeaderRows): array {
                $teamLeader = $teamLeaderRows->get($teamLeaderId);

                return [
                    'team_leader' => $teamLeader,
                    ...$this->groupFigures($groupCallers->values()->all()),
                    'top_up' => (float) ($teamLeader['floor'] ?? 0.0),
                ];
            })
            ->sortBy(fn (array $group): string => $group['team_leader'] === null ? "\u{FFFF}" : mb_strtolower($group['team_leader']['name']))
            ->values()
            ->all();
    }

    /**
     * A group's callers with its head counts: still on the rolls at the
     * end of the period (active), left during it (inactive), and whose
     * target counts here.
     *
     * @param  list<array<string, mixed>>  $callers
     * @return array{callers: list<array<string, mixed>>, total: float, counted: int, on_rolls: int, left: int, top_up: float}
     */
    private function groupFigures(array $callers): array
    {
        $onRolls = count(array_filter($callers, fn (array $caller): bool => $caller['active'] && $caller['left_during_period'] === null));

        return [
            'callers' => $callers,
            'total' => (float) array_sum(array_column($callers, 'share')),
            'counted' => count(array_filter($callers, fn (array $caller): bool => $caller['share'] > 0)),
            'on_rolls' => $onRolls,
            'left' => count($callers) - $onRolls,
            'top_up' => 0.0,
        ];
    }

    /**
     * The Team Leader each caller spent the most days of the period under
     * while in $boss's team and on the rolls (the later one on a tie), or
     * null when they reported straight to a Manager or above. A caller with
     * no day on the rolls in the team goes with the last Team Leader they
     * were under.
     *
     * @param  iterable<Employee>  $callers
     * @return array<int, ?int> caller id => Team Leader id
     */
    private function mainTeamLeaderIds(Employee $boss, EmployeeLookupPeriod $period, iterable $callers): array
    {
        $windows = [];

        foreach ($callers as $caller) {
            $windows[$caller->id] = $period->activeWindow($caller);
        }

        $days = [];
        $lastTeamLeaderIds = [];

        foreach (ReportingTimeline::between($period->start, $period->end)->segments() as $segment) {
            $tree = $segment['tree'];
            $branch = array_flip($tree->descendantIds($boss->id, skipExitedLevels: true));

            foreach ($windows as $callerId => $window) {
                if (! isset($branch[$callerId])) {
                    continue;
                }

                $teamLeaderId = $this->teamLeaderBetween($tree, $callerId, $boss->id) ?? 0;
                $lastTeamLeaderIds[$callerId] = $teamLeaderId;

                $segmentDays = $window === null ? 0 : ReportingTimeline::overlapDays([[$segment['start'], $segment['end']]], $window['start'], $window['end']);

                if ($segmentDays > 0) {
                    $days[$callerId][$teamLeaderId] = ($days[$callerId][$teamLeaderId] ?? 0) + $segmentDays;
                }
            }
        }

        $mainTeamLeaderIds = [];

        foreach ($windows as $callerId => $window) {
            $mainId = $lastTeamLeaderIds[$callerId] ?? 0;
            $mostDays = 0;

            foreach ($days[$callerId] ?? [] as $teamLeaderId => $dayCount) {
                if ($dayCount >= $mostDays) {
                    [$mainId, $mostDays] = [$teamLeaderId, $dayCount];
                }
            }

            $mainTeamLeaderIds[$callerId] = $mainId === 0 ? null : $mainId;
        }

        return $mainTeamLeaderIds;
    }

    /**
     * The first Team Leader above the caller on the way up to $bossId.
     */
    private function teamLeaderBetween(ReportingTree $tree, int $callerId, int $bossId): ?int
    {
        foreach ($tree->ancestorIds($callerId) as $ancestorId) {
            if ($tree->designation($ancestorId) === Employee::DESIGNATION_TEAM_LEADER) {
                return $ancestorId;
            }

            if ($ancestorId === $bossId) {
                return null;
            }
        }

        return null;
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
                'scheme' => $this->calculator->incentiveSchemeFor($employee, $period->end->copy()),
                'months' => $months,
                'incentive' => array_sum(array_column($months, 'incentive')),
            ];
        }

        $month = $period->start->copy();

        if (! $this->monthHasDisbursals($month)) {
            return ['kind' => 'no_data', 'incentive' => 0.0];
        }

        // The scheme of the designation held that month, not today's.
        $scheme = $this->calculator->incentiveSchemeFor($employee, $month);

        if ($scheme === AchievementCalculatorService::INCENTIVE_SCHEME_TEAM_LEADER) {
            $breakdown = $this->calculator->getTeamLeaderIncentiveBreakdown($employee, $month);

            return [
                'kind' => 'team_leader',
                ...$breakdown,
                'steps' => $breakdown['meets_gate'] ? $this->teamLeaderSteps($breakdown) : null,
                'callers' => $this->teamLeaderCallers($employee, $period),
            ];
        }

        if ($scheme === AchievementCalculatorService::INCENTIVE_SCHEME_MANAGER) {
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

        return ['net' => $net, 'incentive' => $this->calculator->getIncentiveFor($employee, $start, $net)];
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
        $shares = TargetLedger::forMonth($period->start)->contributions($teamLeader->id, Employee::DESIGNATION_CALLER);
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
                'target' => TargetLedger::forMonth($period->start)->target($caller->id),
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
     * Who someone is, when they joined (and left), whether they were on the
     * rolls during the period, and — given the stretches they spent in the
     * team — whether they were in it for only part of that time, with the
     * day of each move in and out and how many days of the period they
     * spent in it while on the rolls.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>|null  $spans  null when team membership does not apply
     * @return array{id: int, name: string, initials: string, emp_id: ?string, designation: string, joined_on: ?CarbonImmutable, left_on: ?CarbonImmutable, joined_during_period: ?CarbonImmutable, left_during_period: ?CarbonImmutable, active: bool, exited: bool, moved: bool, movements: list<array{direction: 'in'|'out', on: CarbonImmutable}>, days_here: int, note: ?string}
     */
    private function person(Employee $employee, EmployeeLookupPeriod $period, ?array $spans): array
    {
        $window = $period->activeWindow($employee);
        $exited = EmployeeLookupPeriod::isExited($employee);
        $moved = false;
        $movements = [];
        $daysHere = 0;
        $notes = [];

        if ($window !== null) {
            $daysHere = $spans === null
                ? ReportingTimeline::dayCount($window['start'], $window['end'])
                : ReportingTimeline::overlapDays($spans, $window['start'], $window['end']);
        }

        if ($window === null) {
            $notes[] = 'Not on the rolls in this period.';
        } elseif ($spans !== null && $spans !== []) {
            $activeDays = ReportingTimeline::dayCount($window['start'], $window['end']);

            if ($daysHere < $activeDays) {
                $moved = true;
                $movements = self::movements($spans, $window['start'], $window['end']);
                $notes[] = 'In this team '.implode(', ', array_map(
                    fn (array $span): string => $span[0]->format('d M Y').' – '.$span[1]->format('d M Y'),
                    $spans,
                )).'.';
            }
        }

        $joinedDuringPeriod = $window !== null && $window['start']->gt($period->start)
            ? CarbonImmutable::parse($window['start']->toDateString())
            : null;
        $leftDuringPeriod = $window !== null && $window['end']->lt($period->end->copy()->startOfDay())
            ? CarbonImmutable::parse($window['end']->toDateString())
            : null;

        if ($joinedDuringPeriod !== null) {
            $notes[] = 'Joined '.$joinedDuringPeriod->format('d M Y').'.';
        }

        if ($leftDuringPeriod !== null) {
            $notes[] = 'Left '.$leftDuringPeriod->format('d M Y').'.';
        }

        return [
            'id' => $employee->id,
            'name' => (string) $employee->emp_name,
            'initials' => $employee->initials,
            'emp_id' => $employee->emp_id,
            'designation' => Employee::designationOptions()[$employee->designation] ?? '—',
            'joined_on' => self::dateOrNull($employee->doj) ?? self::dateOrNull($employee->reporting_date),
            'left_on' => $exited ? self::dateOrNull($employee->exit_date) : null,
            'joined_during_period' => $joinedDuringPeriod,
            'left_during_period' => $leftDuringPeriod,
            'active' => $window !== null,
            'exited' => $exited,
            'moved' => $moved,
            'movements' => $movements,
            'days_here' => $daysHere,
            'note' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    /**
     * Each move into or out of the team while on the rolls, in date order:
     * a stretch starting after the first day on the rolls is a move in (on
     * its first day), one ending before the last day a move out (on its
     * last day in the team).
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $spans
     * @return list<array{direction: 'in'|'out', on: CarbonImmutable}>
     */
    private static function movements(array $spans, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $movements = [];

        foreach ($spans as [$spanStart, $spanEnd]) {
            $start = max($spanStart->toDateString(), $fromDay);
            $end = min($spanEnd->toDateString(), $toDay);

            if ($start > $end) {
                continue;
            }

            if ($start > $fromDay) {
                $movements[] = ['direction' => 'in', 'on' => CarbonImmutable::parse($start)];
            }

            if ($end < $toDay) {
                $movements[] = ['direction' => 'out', 'on' => CarbonImmutable::parse($end)];
            }
        }

        return $movements;
    }

    private static function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
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
