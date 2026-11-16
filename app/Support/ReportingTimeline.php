<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Services\ReportingLineService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The reporting tree as it stood on every day of a period.
 *
 * employee_reporting_history dates every reporting change, so the line an
 * employee reported through on any past day can be rebuilt. The period is
 * cut into segments at each change — and on the day after a Team Leader,
 * Manager, Cluster Manager or Business Head left, when their level stops
 * counting — and every segment carries the tree that held on its days.
 *
 * How a change is counted (decided with the user 2026-11-14): if A reports
 * to B until 25 Oct and to C from 26 Oct,
 *  - achievement follows the tree on the day of the event: a case disbursed
 *    on 20 Oct counts for B, one disbursed on 28 Oct for C;
 *  - A's monthly target is shared pro-rata to the days A reported through
 *    each boss (B 25/31, C 6/31), so the shares add up to A's one target;
 *  - headcounts (a Team Leader's caller count, the understaffed-team top-up,
 *    eligible callers) count A in every team A spent MIN_HEADCOUNT_DAYS or
 *    more days of the month in.
 *
 * Reading the history: a joining row sets the line from the start of time;
 * any other change sets it from its effective date (the line before an
 * employee's first non-joining change is that change's old columns). Exit
 * rows never move anybody. The latest line is always today's reporting
 * columns, so a column edited without a history row is still honoured from
 * the last recorded change onward. Built fresh on every call, like
 * ReportingTree.
 *
 * Designations are rebuilt the same way from 'designation' rows: before a
 * promotion the employee holds their old designation, from its effective
 * date the new one, and the latest line is always today's designation. A
 * boss is only linked through the column of the designation they held that
 * day (ReportingTree), so without this a Manager promoted in November would
 * lose their whole October team — the team still points at them through
 * manager_id on those days — and October would read 0 for them.
 */
final class ReportingTimeline
{
    /**
     * Days of a month somebody must spend in a team to count in its
     * headcount — the same ten days a joiner needs before their target
     * counts (AchievementCalculatorService::MIN_TARGET_DAYS).
     */
    public const MIN_HEADCOUNT_DAYS = 10;

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, tree: ReportingTree}>  $segments
     */
    private function __construct(
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
        private readonly array $segments,
    ) {}

    public static function between(CarbonInterface $start, CarbonInterface $end): self
    {
        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::parse($end->toDateString());

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $rows = ReportingTree::employeeRows();
        $lines = self::linesFromHistory();
        $designationLines = self::designationLinesFromHistory();

        $segments = [];
        $cuts = self::cutDates($rows, [...array_values($lines), ...array_values($designationLines)], $start, $end);

        foreach ($cuts as $index => $segmentStart) {
            $segmentEnd = isset($cuts[$index + 1])
                ? CarbonImmutable::parse($cuts[$index + 1])->subDay()
                : $end;

            $segments[] = [
                'start' => CarbonImmutable::parse($segmentStart),
                'end' => $segmentEnd,
                'tree' => self::treeFor($rows, $lines, $designationLines, $segmentStart),
            ];
        }

        return new self($start, $end, $segments);
    }

    /**
     * The whole calendar month $month falls in.
     */
    public static function forMonth(CarbonInterface $month): self
    {
        return self::between(
            CarbonImmutable::parse($month->toDateString())->startOfMonth(),
            CarbonImmutable::parse($month->toDateString())->endOfMonth(),
        );
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function days(): int
    {
        return self::dayCount($this->start, $this->end);
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, tree: ReportingTree}>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function treeOn(CarbonInterface $date): ReportingTree
    {
        $day = $date->toDateString();

        foreach ($this->segments as $segment) {
            if ($segment['start']->toDateString() <= $day && $day <= $segment['end']->toDateString()) {
                return $segment['tree'];
            }
        }

        return $day < $this->start->toDateString()
            ? $this->segments[0]['tree']
            : $this->segments[array_key_last($this->segments)]['tree'];
    }

    /**
     * The stretches of the period with the ids counted in $bossId's branch
     * on them — the boss themselves included unless $includeBoss is false.
     * Neighbouring segments that count the same people are merged, so a
     * branch nobody joined or left is one stretch.
     *
     * With $withinId, only the days $bossId sat inside $withinId's branch
     * count: the share of $bossId's figures that belongs to $withinId (a
     * caller's contribution to the Team Leader they moved away from).
     * $onlyDesignation keeps one level, e.g. callers only.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, ids: list<int>}>
     */
    public function branchRanges(
        int $bossId,
        bool $skipExitedLevels = true,
        bool $includeBoss = true,
        ?int $withinId = null,
        ?int $onlyDesignation = null,
    ): array {
        $ranges = [];

        foreach ($this->segments as $segment) {
            $tree = $segment['tree'];
            $ids = [];

            if ($this->sitsWithin($tree, $bossId, $withinId, $skipExitedLevels)) {
                $ids = $tree->descendantIds($bossId, $skipExitedLevels);

                if ($includeBoss) {
                    $ids[] = $bossId;
                }

                if ($onlyDesignation !== null) {
                    $ids = array_filter($ids, fn (int $id): bool => $tree->designation($id) === $onlyDesignation);
                }

                $ids = array_values(array_unique($ids));
                sort($ids);
            }

            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last]['ids'] === $ids) {
                $ranges[$last]['end'] = $segment['end'];

                continue;
            }

            $ranges[] = ['start' => $segment['start'], 'end' => $segment['end'], 'ids' => $ids];
        }

        return array_values(array_filter($ranges, fn (array $range): bool => $range['ids'] !== []));
    }

    /**
     * For everyone who was in $bossId's branch at any point of the period,
     * the stretches they were in it.
     *
     * @return array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>> employee id => spans
     */
    public function branchSpans(
        int $bossId,
        bool $skipExitedLevels = true,
        bool $includeBoss = true,
        ?int $withinId = null,
        ?int $onlyDesignation = null,
    ): array {
        $spans = [];

        foreach ($this->branchRanges($bossId, $skipExitedLevels, $includeBoss, $withinId, $onlyDesignation) as $range) {
            foreach ($range['ids'] as $id) {
                $last = isset($spans[$id]) ? array_key_last($spans[$id]) : null;

                if ($last !== null && $spans[$id][$last][1]->addDay()->isSameDay($range['start'])) {
                    $spans[$id][$last][1] = $range['end'];

                    continue;
                }

                $spans[$id][] = [$range['start'], $range['end']];
            }
        }

        return $spans;
    }

    /**
     * Days of the period each employee spent in $bossId's branch.
     *
     * @return array<int, int> employee id => days
     */
    public function branchDays(
        int $bossId,
        bool $skipExitedLevels = true,
        bool $includeBoss = true,
        ?int $withinId = null,
        ?int $onlyDesignation = null,
    ): array {
        return array_map(
            fn (array $spans): int => self::overlapDays($spans, $this->start, $this->end),
            $this->branchSpans($bossId, $skipExitedLevels, $includeBoss, $withinId, $onlyDesignation),
        );
    }

    /**
     * Callers who count in $bossId's headcount for the period: in the
     * branch (or, with $directOnly, reporting straight to them) for at
     * least MIN_HEADCOUNT_DAYS days while on the rolls — days before their
     * reporting date or after their exit date never count (user decision
     * 2026-11-16: the ten days follow joining, leaving and movement; a
     * caller who left still counts if they had ten days that month). A
     * caller moved mid-month can count in both teams.
     *
     * @return list<int>
     */
    public function headcountCallerIds(int $bossId, bool $directOnly = false): array
    {
        if ($this->designation($bossId) === Employee::DESIGNATION_CALLER) {
            return [$bossId];
        }

        $spans = $directOnly
            ? $this->directReportSpans($bossId)
            : $this->branchSpans($bossId, includeBoss: false, onlyDesignation: Employee::DESIGNATION_CALLER);

        $spans = array_filter(
            $spans,
            fn (int $id): bool => $this->designation($id) === Employee::DESIGNATION_CALLER,
            ARRAY_FILTER_USE_KEY,
        );

        return array_keys(array_filter(
            $this->daysOnTheRolls($spans),
            fn (int $dayCount): bool => $dayCount >= self::MIN_HEADCOUNT_DAYS,
        ));
    }

    /**
     * Days of each person's $spans that fall inside the period while they
     * were on the rolls — reporting date to exit date (OnRollsWindow).
     *
     * @param  array<int, list<array{0: CarbonInterface, 1: CarbonInterface}>>  $spans  employee id => spans
     * @return array<int, int> employee id => days, in the order of $spans
     */
    public function daysOnTheRolls(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('id', array_keys($spans))
            ->get(['id', 'reporting_date', 'exit_status', 'exit_date'])
            ->keyBy('id');

        $days = [];

        foreach ($spans as $id => $employeeSpans) {
            $window = $employees->has($id) ? OnRollsWindow::forMonth($employees[$id], $this->start, $this->end) : null;

            $days[$id] = $window === null ? 0 : self::overlapDays($employeeSpans, $window['start'], $window['end']);
        }

        return $days;
    }

    public function designation(int $employeeId): ?int
    {
        return $this->segments[0]['tree']->designation($employeeId);
    }

    /**
     * The designation $employee held on $day: from their 'designation'
     * history rows, today's designation once the latest one applies. Reads
     * only that employee's rows, so it is cheap enough per figure.
     */
    public static function designationOn(Employee $employee, CarbonInterface $day): ?int
    {
        $line = self::lineOn(self::designationLinesFromHistory($employee->id)[$employee->id] ?? null, $day->toDateString());

        return $line['designation'] ?? ($employee->designation === null ? null : (int) $employee->designation);
    }

    /**
     * Everyone in $bossId's branch on at least one day of the period.
     *
     * @return list<int>
     */
    public function branchIds(int $bossId, bool $skipExitedLevels = true, bool $includeBoss = true): array
    {
        return array_keys($this->branchSpans($bossId, $skipExitedLevels, $includeBoss));
    }

    /**
     * Days of the period each person spent reporting straight to $bossId.
     *
     * @return array<int, int> employee id => days
     */
    public function directReportDays(int $bossId, bool $skipExitedLevels = false): array
    {
        $days = [];

        foreach ($this->segments as $segment) {
            $length = self::dayCount($segment['start'], $segment['end']);

            foreach ($segment['tree']->childIds($bossId, $skipExitedLevels) as $childId) {
                $days[$childId] = ($days[$childId] ?? 0) + $length;
            }
        }

        return $days;
    }

    /**
     * The stretches of the period each person reported straight to $bossId.
     *
     * @return array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>> employee id => spans
     */
    private function directReportSpans(int $bossId): array
    {
        $spans = [];

        foreach ($this->segments as $segment) {
            foreach ($segment['tree']->childIds($bossId) as $childId) {
                $last = isset($spans[$childId]) ? array_key_last($spans[$childId]) : null;

                if ($last !== null && $spans[$childId][$last][1]->addDay()->isSameDay($segment['start'])) {
                    $spans[$childId][$last][1] = $segment['end'];

                    continue;
                }

                $spans[$childId][] = [$segment['start'], $segment['end']];
            }
        }

        return $spans;
    }

    /**
     * Everyone reporting straight to $bossId on at least one day of the
     * period.
     *
     * @return list<int>
     */
    public function childIdsDuring(int $bossId, bool $skipExitedLevels = false): array
    {
        return array_keys($this->directReportDays($bossId, $skipExitedLevels));
    }

    /**
     * Days of [$from, $to] covered by $spans.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $spans
     */
    public static function overlapDays(array $spans, CarbonInterface $from, CarbonInterface $to): int
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $days = 0;

        foreach ($spans as [$spanStart, $spanEnd]) {
            $start = max($spanStart->toDateString(), $fromDay);
            $end = min($spanEnd->toDateString(), $toDay);

            if ($start <= $end) {
                $days += self::dayCount(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
            }
        }

        return $days;
    }

    public static function dayCount(CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) CarbonImmutable::parse($start->toDateString())
            ->diffInDays(CarbonImmutable::parse($end->toDateString())) + 1;
    }

    private function sitsWithin(ReportingTree $tree, int $bossId, ?int $withinId, bool $skipExitedLevels): bool
    {
        return $withinId === null
            || $withinId === $bossId
            || in_array($bossId, $tree->descendantIds($withinId, $skipExitedLevels), true);
    }

    /**
     * Each employee's reporting lines from their history, oldest first.
     *
     * @return array<int, list<array{from: ?string, columns: array<string, ?int>}>>
     */
    private static function linesFromHistory(): array
    {
        $changes = EmployeeReportingHistory::query()
            ->toBase()
            ->whereNotIn('change_type', ReportingLineService::NON_REPORTING_CHANGES)
            ->orderBy('employee_id')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        $lines = [];

        foreach ($changes->groupBy('employee_id') as $employeeId => $employeeChanges) {
            $list = [];

            foreach ($employeeChanges->values() as $index => $change) {
                if ($index === 0) {
                    if ($change->change_type === 'joining') {
                        $list[] = ['from' => null, 'columns' => self::columnsOf($change, 'new_')];

                        continue;
                    }

                    $list[] = ['from' => null, 'columns' => self::columnsOf($change, 'old_')];
                }

                $list[] = [
                    'from' => substr((string) $change->effective_date, 0, 10),
                    'columns' => self::columnsOf($change, 'new_'),
                ];
            }

            $lines[(int) $employeeId] = $list;
        }

        return $lines;
    }

    /**
     * Each employee's designations from their 'designation' rows, oldest
     * first: the first row's old designation from the start of time, then
     * each row's new one from its effective date.
     *
     * @return array<int, list<array{from: ?string, designation: ?int}>>
     */
    private static function designationLinesFromHistory(?int $employeeId = null): array
    {
        $changes = EmployeeReportingHistory::query()
            ->toBase()
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderBy('employee_id')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get(['employee_id', 'effective_date', 'old_designation', 'new_designation']);

        $lines = [];

        foreach ($changes->groupBy('employee_id') as $id => $employeeChanges) {
            $list = [['from' => null, 'designation' => self::designationOf($employeeChanges->first()->old_designation)]];

            foreach ($employeeChanges as $change) {
                $list[] = [
                    'from' => substr((string) $change->effective_date, 0, 10),
                    'designation' => self::designationOf($change->new_designation),
                ];
            }

            $lines[(int) $id] = $list;
        }

        return $lines;
    }

    private static function designationOf(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, ?int>
     */
    private static function columnsOf(object $change, string $prefix): array
    {
        $columns = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $value = $change->{$prefix.$column} ?? null;
            $columns[$column] = $value === null ? null : (int) $value;
        }

        return $columns;
    }

    /**
     * The day each segment starts on: the period's first day, every
     * reporting or designation change inside it, and the day after a level
     * above caller left (from then on their level stops counting).
     *
     * @param  Collection<int, object>  $rows
     * @param  list<list<array{from: ?string}>>  $lines  every employee's reporting and designation lines
     * @return list<string>
     */
    private static function cutDates(Collection $rows, array $lines, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $startDay = $start->toDateString();
        $endDay = $end->toDateString();
        $cuts = [$startDay => true];

        foreach ($lines as $list) {
            foreach ($list as $line) {
                if ($line['from'] !== null && $line['from'] > $startDay && $line['from'] <= $endDay) {
                    $cuts[$line['from']] = true;
                }
            }
        }

        foreach ($rows as $row) {
            if ((int) $row->designation === Employee::DESIGNATION_CALLER
                || strtolower(trim((string) $row->exit_status)) !== 'yes'
                || blank($row->exit_date)) {
                continue;
            }

            $firstDayGone = CarbonImmutable::parse(substr((string) $row->exit_date, 0, 10))->addDay()->toDateString();

            if ($firstDayGone > $startDay && $firstDayGone <= $endDay) {
                $cuts[$firstDayGone] = true;
            }
        }

        $days = array_keys($cuts);
        sort($days);

        return $days;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<int, list<array{from: ?string, columns: array<string, ?int>}>>  $lines
     * @param  array<int, list<array{from: ?string, designation: ?int}>>  $designationLines
     */
    private static function treeFor(Collection $rows, array $lines, array $designationLines, string $day): ReportingTree
    {
        $rowsOnDay = $rows->map(function (object $row) use ($lines, $designationLines, $day): object {
            $columns = self::lineOn($lines[(int) $row->id] ?? null, $day)['columns'] ?? null;
            $designation = self::lineOn($designationLines[(int) $row->id] ?? null, $day)['designation'] ?? null;

            if ($columns === null && $designation === null) {
                return $row;
            }

            $copy = clone $row;

            foreach ($columns ?? [] as $column => $value) {
                $copy->{$column} = $value;
            }

            if ($designation !== null) {
                $copy->designation = $designation;
            }

            return $copy;
        });

        return ReportingTree::fromRows($rowsOnDay, CarbonImmutable::parse($day));
    }

    /**
     * The line of an employee's history that held on $day, or null when
     * today's values apply (no history, or $day falls in the latest line).
     *
     * @template TLine of array{from: ?string}
     *
     * @param  list<TLine>|null  $list
     * @return TLine|null
     */
    private static function lineOn(?array $list, string $day): ?array
    {
        if ($list === null || $list === []) {
            return null;
        }

        $lastIndex = array_key_last($list);

        for ($index = $lastIndex; $index >= 0; $index--) {
            if ($list[$index]['from'] === null || $list[$index]['from'] <= $day) {
                return $index === $lastIndex ? null : $list[$index];
            }
        }

        return null;
    }
}
