<?php

namespace App\Support;

use App\Models\Employee;

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
 * Loaded fresh each time and never cached: employees are created and
 * re-pointed within a single request (and a single test), and a stale tree
 * would quietly hand somebody the wrong team.
 */
final class ReportingTree
{
    /**
     * @param  array<int, array{designation: int, exited: bool, boss_id: ?int, name: string}>  $nodes
     * @param  array<int, array<int, int>>  $children  boss id => direct report ids
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $children,
    ) {}

    public static function load(): self
    {
        $rows = Employee::query()
            ->toBase()
            ->orderBy('id')
            ->get(['id', 'emp_name', 'designation', 'exit_status', ...array_keys(Employee::REPORTING_COLUMNS)]);

        $designations = $rows
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (int) $row->designation])
            ->all();

        $nodes = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $bossId = self::resolveBossId($row, $designations);

            $nodes[$id] = [
                'designation' => $designations[$id],
                // Compared the way MySQL's case-insensitive `!= 'yes'` always did.
                'exited' => strtolower(trim((string) $row->exit_status)) === 'yes',
                'boss_id' => $bossId,
                'name' => (string) $row->emp_name,
            ];

            if ($bossId !== null) {
                $children[$bossId][] = $id;
            }
        }

        return new self($nodes, $children);
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
     * The people reporting straight to the employee.
     *
     * @return array<int, int>
     */
    public function childIds(int $employeeId, bool $skipExitedLevels = false): array
    {
        $childIds = $this->children[$employeeId] ?? [];

        if (! $skipExitedLevels) {
            return $childIds;
        }

        return array_values(array_filter(
            $childIds,
            fn (int $childId): bool => ! $this->isExitedLevel($childId),
        ));
    }

    /**
     * Everyone below the employee, nearest level first.
     *
     * $skipExitedLevels is the split between the two walks in
     * .ai/rules/support.md: target, incentive and target-duty maths stops
     * at an exited Team Leader, Manager, Cluster Manager or Business Head
     * and leaves out everyone under them, while record visibility carries
     * on through. An exited caller counts either way, as it always has.
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
     * @param  array<int, int>  $designations  employee id => designation
     */
    private static function resolveBossId(object $row, array $designations): ?int
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

            if (($designations[$bossId] ?? null) === $bossDesignation) {
                return $bossId;
            }
        }

        return null;
    }

    /** An exited level above caller — the point where counting stops. */
    private function isExitedLevel(int $employeeId): bool
    {
        return $this->isExited($employeeId)
            && $this->designation($employeeId) !== Employee::DESIGNATION_CALLER;
    }
}
