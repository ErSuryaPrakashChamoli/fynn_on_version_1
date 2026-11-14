<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
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

        $segments = [];
        $cuts = self::cutDates($rows, $lines, $start, $end);

        foreach ($cuts as $index => $segmentStart) {
            $segmentEnd = isset($cuts[$index + 1])
                ? CarbonImmutable::parse($cuts[$index + 1])->subDay()
                : $end;

            $segments[] = [
                'start' => CarbonImmutable::parse($segmentStart),
                'end' => $segmentEnd,
                'tree' => self::treeFor($rows, $lines, $segmentStart),
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
     * least MIN_HEADCOUNT_DAYS days. A caller moved mid-month can count in
     * both teams.
     *
     * @return list<int>
     */
    public function headcountCallerIds(int $bossId, bool $directOnly = false): array
    {
        if ($this->designation($bossId) === Employee::DESIGNATION_CALLER) {
            return [$bossId];
        }

        $days = $directOnly
            ? $this->directReportDays($bossId)
            : $this->branchDays($bossId, includeBoss: false, onlyDesignation: Employee::DESIGNATION_CALLER);

        return array_keys(array_filter(
            $days,
            fn (int $dayCount, int $id): bool => $dayCount >= self::MIN_HEADCOUNT_DAYS
                && $this->designation($id) === Employee::DESIGNATION_CALLER,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function designation(int $employeeId): ?int
    {
        return $this->segments[0]['tree']->designation($employeeId);
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
            ->where('change_type', '!=', 'exit')
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
     * reporting change inside it, and the day after a level above caller
     * left (from then on their level stops counting).
     *
     * @param  Collection<int, object>  $rows
     * @param  array<int, list<array{from: ?string, columns: array<string, ?int>}>>  $lines
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
     */
    private static function treeFor(Collection $rows, array $lines, string $day): ReportingTree
    {
        $rowsOnDay = $rows->map(function (object $row) use ($lines, $day): object {
            $columns = self::columnsOn($lines[(int) $row->id] ?? null, $day);

            if ($columns === null) {
                return $row;
            }

            $copy = clone $row;

            foreach ($columns as $column => $value) {
                $copy->{$column} = $value;
            }

            return $copy;
        });

        return ReportingTree::fromRows($rowsOnDay, CarbonImmutable::parse($day));
    }

    /**
     * The columns from an employee's history that held on $day, or null
     * when today's reporting columns apply (no history, or $day falls in
     * the latest line).
     *
     * @param  list<array{from: ?string, columns: array<string, ?int>}>|null  $list
     * @return array<string, ?int>|null
     */
    private static function columnsOn(?array $list, string $day): ?array
    {
        if ($list === null || $list === []) {
            return null;
        }

        $lastIndex = array_key_last($list);

        for ($index = $lastIndex; $index >= 0; $index--) {
            if ($list[$index]['from'] === null || $list[$index]['from'] <= $day) {
                return $index === $lastIndex ? null : $list[$index]['columns'];
            }
        }

        return null;
    }
}
