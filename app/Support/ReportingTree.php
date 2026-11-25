<?php

namespace App\Support;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The company's reporting tree, built from one read of the employees table.
 *
 * An employee's direct boss is the nearest filled reporting column
 * (Employee::REPORTING_COLUMNS) that points at an employee of that
 * column's designation AND above the employee's own level. A skipped level
 * is a null column, so a Team Leader with no Manager hangs straight off
 * their Cluster Manager, and a caller with no Team Leader off their Manager.
 *
 * Links come only from that direct boss, never from the copied higher
 * columns on a caller's own row, which are not always kept up to date. A
 * column pointing at the wrong designation — a Manager's cluster_id holding
 * another Manager — is ignored rather than trusted, and because a boss
 * always outranks their report the tree can never loop.
 *
 * Read once per request through ReportingSnapshot, which is retired the
 * moment anything writes to employees or employee_reporting_history:
 * employees are created and re-pointed within a single request (and a
 * single test), and a stale tree would quietly hand somebody the wrong
 * team. The tree itself is immutable, so sharing one is safe.
 *
 * load() is today's tree, read straight off the reporting columns.
 * load($date) is the tree as it stood on that day, rebuilt from
 * employee_reporting_history by ReportingTimeline: a reporting change only
 * moves somebody from its effective date, so a month before the change is
 * never restated with the new team. Anything period-based — targets,
 * achievement, incentives, team listings for a month — must walk the tree
 * of the day it is counting, never today's.
 *
 * Every node carries two links upward. boss_id is the direct boss on record,
 * exited or not — the walk record visibility uses. count_boss_id is the
 * nearest boss STILL ON THE ROLLS that day, found by reading up the
 * employee's own reporting columns (user decision 2026-11-24): a caller
 * whose Team Leader and Cluster Manager have both left counts under the
 * Business Head their record still names. The counting walk (targets,
 * achievement, headcount, incentives — $skipExitedLevels) follows
 * count_boss_id, so nobody still working falls out of every branch; with
 * nobody active on their record they hang off no one and are counted only
 * in the company total (TargetLedger::brokenLineIds()).
 */
final class ReportingTree
{
    /**
     * @param  array<int, array{designation: int, exited: bool, boss_id: ?int, count_boss_id: ?int, name: string}>  $nodes
     * @param  array<int, array<int, int>>  $children  boss id => direct report ids
     * @param  array<int, array<int, int>>  $countChildren  active boss id => ids counted straight under them
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $children,
        private readonly array $countChildren = [],
    ) {}

    /**
     * The reporting tree today, or — with $asOf — as it stood on that day.
     */
    public static function load(?CarbonInterface $asOf = null): self
    {
        if ($asOf !== null) {
            return ReportingTimeline::between($asOf, $asOf)->treeOn($asOf);
        }

        return ReportingSnapshot::remember('tree:today', fn (): self => self::fromRows(self::employeeRows(), null));
    }

    /**
     * Every employee with the columns the tree is built from. Shared for the
     * request (ReportingSnapshot): read the rows, never modify them — clone
     * a row before changing it, as ReportingTimeline does.
     *
     * @return Collection<int, object>
     */
    public static function employeeRows(): Collection
    {
        return ReportingSnapshot::remember('employee-rows', fn (): Collection => Employee::query()
            ->toBase()
            ->orderBy('id')
            ->get(['id', 'emp_name', 'designation', 'exit_status', 'exit_date', ...array_keys(Employee::REPORTING_COLUMNS)]));
    }

    /**
     * Build the tree from employee rows whose reporting columns are the ones
     * that held on $asOf (today's columns when $asOf is null).
     *
     * With a date, somebody counts as exited only once their exit date has
     * passed — a Team Leader who left in November still led their team in
     * October. Without one, exit_status alone decides, as it always has for
     * today's tree.
     *
     * @param  iterable<int, object>  $rows
     */
    public static function fromRows(iterable $rows, ?CarbonInterface $asOf): self
    {
        $designations = [];

        foreach ($rows as $row) {
            $designations[(int) $row->id] = (int) $row->designation;
        }

        $asOfDate = $asOf?->toDateString();
        $exited = [];

        foreach ($rows as $row) {
            $exited[(int) $row->id] = self::exitedOn($row, $asOfDate);
        }

        $nodes = [];
        $children = [];
        $countChildren = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $bossId = self::resolveBossId($row, $designations);
            $countBossId = self::resolveBossId($row, $designations, $exited);

            $nodes[$id] = [
                'designation' => $designations[$id],
                'exited' => $exited[$id],
                'boss_id' => $bossId,
                'count_boss_id' => $countBossId,
                'name' => (string) $row->emp_name,
            ];

            if ($bossId !== null) {
                $children[$bossId][] = $id;
            }

            if ($countBossId !== null) {
                $countChildren[$countBossId][] = $id;
            }
        }

        return new self($nodes, $children, $countChildren);
    }

    /**
     * @return array<int, int>
     */
    public function employeeIds(): array
    {
        return array_keys($this->nodes);
    }

    public function designation(int $employeeId): ?int
    {
        return $this->nodes[$employeeId]['designation'] ?? null;
    }

    public function name(int $employeeId): ?string
    {
        return $this->nodes[$employeeId]['name'] ?? null;
    }

    public function isExited(int $employeeId): bool
    {
        return $this->nodes[$employeeId]['exited'] ?? false;
    }

    public function bossId(int $employeeId): ?int
    {
        return $this->nodes[$employeeId]['boss_id'] ?? null;
    }

    /**
     * The boss the employee counts under: the nearest one on their own
     * record who is still on the rolls (null when nobody is).
     */
    public function countBossId(int $employeeId): ?int
    {
        return $this->nodes[$employeeId]['count_boss_id'] ?? null;
    }

    /**
     * The people reporting straight to the employee.
     *
     * With $skipExitedLevels, the people COUNTED straight under them: their
     * own direct reports, plus anyone whose nearer bosses have all left and
     * whose record names them next — never an exited Team Leader, Manager,
     * Cluster Manager or Business Head.
     *
     * @return array<int, int>
     */
    public function childIds(int $employeeId, bool $skipExitedLevels = false): array
    {
        if (! $skipExitedLevels) {
            return $this->children[$employeeId] ?? [];
        }

        return array_values(array_filter(
            $this->countChildren[$employeeId] ?? [],
            fn (int $childId): bool => ! $this->isExitedLevel($childId),
        ));
    }

    /**
     * Everyone below the employee, nearest level first.
     *
     * $skipExitedLevels is the split between the two walks in
     * .ai/rules/support.md: target, incentive and target-duty maths leaves
     * out an exited Team Leader, Manager, Cluster Manager or Business Head
     * and counts the people under them with the nearest boss still on the
     * rolls (count_boss_id), while record visibility walks the direct
     * links straight through. An exited caller counts either way, as it
     * always has.
     *
     * @return array<int, int>
     */
    public function descendantIds(int $employeeId, bool $skipExitedLevels = false): array
    {
        $found = [];
        $frontier = [$employeeId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                array_push($next, ...$this->childIds($id, $skipExitedLevels));
            }

            array_push($found, ...$next);
            $frontier = $next;
        }

        return $found;
    }

    /**
     * Every boss above the employee, nearest first.
     *
     * @return array<int, int>
     */
    public function ancestorIds(int $employeeId): array
    {
        $ancestorIds = [];

        for ($bossId = $this->bossId($employeeId); $bossId !== null; $bossId = $this->bossId($bossId)) {
            $ancestorIds[] = $bossId;
        }

        return $ancestorIds;
    }

    /**
     * The nearest boss holding one of $designations — with $activeOnly,
     * the nearest one still on the rolls.
     *
     * @param  array<int, int>  $designations
     */
    public function nearestAncestorId(int $employeeId, array $designations, bool $activeOnly = false): ?int
    {
        foreach ($this->ancestorIds($employeeId) as $ancestorId) {
            if (in_array($this->designation($ancestorId), $designations, true)
                && ! ($activeOnly && $this->isExited($ancestorId))) {
                return $ancestorId;
            }
        }

        return null;
    }

    /**
     * The top of the employee's own branch: their Cluster Manager, or the
     * highest boss below Business Head level when that level is skipped.
     * Never climbs to a Business Head, which would merge every cluster
     * into one branch.
     */
    public function branchRootId(int $employeeId): int
    {
        $clusterRank = Employee::designationRank(Employee::DESIGNATION_CLUSTER);
        $rootId = $employeeId;

        while (Employee::designationRank($this->designation($rootId)) < $clusterRank) {
            $bossId = $this->bossId($rootId);

            if ($bossId === null || Employee::designationRank($this->designation($bossId)) > $clusterRank) {
                break;
            }

            $rootId = $bossId;
        }

        return $rootId;
    }

    /**
     * The nearest reporting column pointing at an employee of that column's
     * designation above the row's own level. With $exited, a boss who has
     * left is passed over and the next column up is read instead.
     *
     * @param  array<int, int>  $designations  employee id => designation
     * @param  array<int, bool>|null  $exited  employee id => exited that day
     */
    private static function resolveBossId(object $row, array $designations, ?array $exited = null): ?int
    {
        $ownRank = Employee::designationRank((int) $row->designation);

        if ($ownRank === 0) {
            return null;
        }

        foreach (Employee::REPORTING_COLUMNS as $column => $bossDesignation) {
            $bossId = (int) $row->{$column};

            if ($bossId === 0 || Employee::designationRank($bossDesignation) <= $ownRank) {
                continue;
            }

            if (($designations[$bossId] ?? null) === $bossDesignation && ! ($exited[$bossId] ?? false)) {
                return $bossId;
            }
        }

        return null;
    }

    /**
     * Compared the way MySQL's case-insensitive `!= 'yes'` always did. An
     * exit with no date recorded has no day to start from, so it counts on
     * every day.
     */
    private static function exitedOn(object $row, ?string $asOfDate): bool
    {
        if (strtolower(trim((string) $row->exit_status)) !== 'yes') {
            return false;
        }

        if ($asOfDate === null || blank($row->exit_date ?? null)) {
            return true;
        }

        // The exit date is the last day on the rolls.
        return substr((string) $row->exit_date, 0, 10) < $asOfDate;
    }

    /** An exited level above caller — the point where counting stops. */
    private function isExitedLevel(int $employeeId): bool
    {
        return $this->isExited($employeeId)
            && $this->designation($employeeId) !== Employee::DESIGNATION_CALLER;
    }
}
