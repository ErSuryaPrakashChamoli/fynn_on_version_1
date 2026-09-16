<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Support\ReportingTree;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single writer of an employee's reporting columns.
 *
 * An employee only ever chooses the one person they report to — anyone
 * more senior, skipping levels if need be. Every reporting column follows
 * from that boss: each boss up the chain goes into the column for their
 * level, and every level the chain skips stays null (see
 * Employee::REPORTING_COLUMNS and ReportingTree). Moving somebody re-fills
 * the columns of everyone below them the same way, so a Manager moved to a
 * new Cluster Manager takes their whole team with them.
 *
 * reporting_date is never touched: a reporting-line change moves an
 * existing employee and must not make them look like a new joiner to
 * AchievementCalculatorService.
 */
class ReportingLineService
{
    public const CHANGE_REPORTING = 'reporting_change';

    public const CHANGE_TRANSFER = 'transfer';

    /**
     * Whether an employee at $designation has to report to somebody.
     *
     * Nobody reports directly to the Admin, so every level below Business
     * Head needs a boss — except a Cluster Manager while there is no
     * Business Head on the rolls to report to.
     */
    public function requiresBoss(?int $designation): bool
    {
        if (! $this->canHaveBoss($designation)) {
            return false;
        }

        if ($designation !== Employee::DESIGNATION_CLUSTER) {
            return true;
        }

        return Employee::query()
            ->where('designation', Employee::DESIGNATION_BUSINESS_HEAD)
            ->where('exit_status', '!=', 'yes')
            ->exists();
    }

    /**
     * Why $bossId cannot be the boss of an employee at $designation, or
     * null when they can.
     */
    public function bossProblem(?int $designation, ?int $bossId, ?int $employeeId = null): ?string
    {
        $label = Employee::designationOptions()[$designation] ?? 'employee';

        if ($bossId === null) {
            return $this->requiresBoss($designation)
                ? "Choose who this {$label} reports to — nobody reports directly to the Admin."
                : null;
        }

        if (! $this->canHaveBoss($designation)) {
            return "A {$label} does not report to anybody in the hierarchy.";
        }

        $boss = Employee::query()->find($bossId);

        if (! $boss) {
            return 'The selected boss no longer exists.';
        }

        if ($boss->id === $employeeId) {
            return 'An employee cannot report to themselves.';
        }

        if (strtolower(trim((string) $boss->exit_status)) === 'yes') {
            return "{$boss->emp_name} has left and cannot be reported to.";
        }

        if (Employee::designationRank($boss->designation) <= Employee::designationRank($designation)) {
            return "{$boss->emp_name} is not senior to a {$label}.";
        }

        return null;
    }

    /**
     * Why $employee cannot take $designation, or null when they can. A
     * promotion is always fine; a demotion must not leave anybody
     * reporting to somebody who is no longer senior to them.
     */
    public function designationProblem(Employee $employee, ?int $designation): ?string
    {
        $tree = ReportingTree::load();
        $newRank = Employee::designationRank($designation);

        $stranded = array_filter(
            $tree->childIds($employee->id),
            fn (int $reportId): bool => Employee::designationRank($tree->designation($reportId)) >= $newRank,
        );

        if ($stranded === []) {
            return null;
        }

        return count($stranded)." employee(s) reporting to {$employee->emp_name} would no longer have a more senior boss. Move them to another boss first.";
    }

    /**
     * Active employees an employee at $designation may report to, grouped
     * by level, most senior level first.
     *
     * @return array<string, array<int, string>>
     */
    public function bossOptions(?int $designation, ?int $exceptEmployeeId = null): array
    {
        if (! $this->canHaveBoss($designation)) {
            return [];
        }

        $ownRank = Employee::designationRank($designation);

        $bossDesignations = array_reverse(array_values(array_filter(
            Employee::REPORTING_COLUMNS,
            fn (int $bossDesignation): bool => Employee::designationRank($bossDesignation) > $ownRank,
        )));

        $candidates = Employee::query()
            ->whereIn('designation', $bossDesignations)
            ->where('exit_status', '!=', 'yes')
            ->when($exceptEmployeeId, fn ($query) => $query->whereKeyNot($exceptEmployeeId))
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id', 'designation']);

        $options = [];

        foreach ($bossDesignations as $bossDesignation) {
            $group = $candidates->where('designation', $bossDesignation);

            if ($group->isNotEmpty()) {
                $options[Employee::designationOptions()[$bossDesignation]] = $group
                    ->mapWithKeys(fn (Employee $boss): array => [$boss->id => "{$boss->emp_name} - ({$boss->emp_id})"])
                    ->all();
            }
        }

        return $options;
    }

    /**
     * The reporting line down to $bossId, top first, e.g.
     * "Asha (Business Head) › Kanak (Cluster Manager)".
     */
    public function lineSummary(int $bossId): string
    {
        $tree = ReportingTree::load();
        $labels = Employee::designationOptions();

        return collect([...array_reverse($tree->ancestorIds($bossId)), $bossId])
            ->map(fn (int $id): string => $tree->name($id).' ('.($labels[$tree->designation($id)] ?? '—').')')
            ->implode(' › ');
    }

    /**
     * The reporting columns of anybody who reports straight to $bossId.
     *
     * Built from the reporting tree rather than copied from the boss's own
     * columns, so a boss whose columns have drifted cannot pass the drift on.
     *
     * @return array<string, int|null>
     */
    public function columnsUnder(?int $bossId, ?ReportingTree $tree = null): array
    {
        $columns = array_fill_keys(array_keys(Employee::REPORTING_COLUMNS), null);

        if ($bossId === null) {
            return $columns;
        }

        $tree ??= ReportingTree::load();

        foreach ([$bossId, ...$tree->ancestorIds($bossId)] as $id) {
            $column = array_search($tree->designation($id), Employee::REPORTING_COLUMNS, true);

            if ($column !== false) {
                $columns[$column] = $id;
            }
        }

        return $columns;
    }

    /**
     * Make $employee report to $bossId, and re-fill the reporting columns
     * of everyone below them to match.
     *
     * A history row is written for every employee on the rolls whose
     * columns actually changed; an exited employee's columns are kept in
     * step without adding to a lifecycle that ended at their exit.
     *
     * @return Collection<int, int> ids of every employee whose columns changed
     *
     * @throws ValidationException when $bossId is not an allowed boss
     */
    public function reportTo(
        Employee $employee,
        ?int $bossId,
        CarbonInterface|string|null $effectiveDate = null,
        string $changeType = self::CHANGE_REPORTING,
        ?string $remarks = null,
        ?int $updatedBy = null,
    ): Collection {
        $problem = $this->bossProblem($employee->designation, $bossId, $employee->id);

        if ($problem !== null) {
            throw ValidationException::withMessages(['reports_to' => $problem]);
        }

        $effectiveDate = Carbon::parse($effectiveDate ?? today())->toDateString();

        return DB::transaction(function () use ($employee, $bossId, $effectiveDate, $changeType, $remarks, $updatedBy): Collection {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $employee->refresh();

            $changed = collect();

            if ($this->applyColumns($employee, $this->columnsUnder($bossId), $effectiveDate, $changeType, $remarks, $updatedBy)) {
                $changed->push($employee->id);
            }

            // Loaded after the move, so everyone below hangs off the new line.
            $tree = ReportingTree::load();
            $descendantIds = $tree->descendantIds($employee->id);

            $descendants = Employee::query()
                ->whereIn('id', $descendantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($descendantIds as $descendantId) {
                $descendant = $descendants->get($descendantId);

                if ($descendant && $this->applyColumns(
                    $descendant,
                    $this->columnsUnder($tree->bossId($descendantId), $tree),
                    $effectiveDate,
                    $changeType,
                    $remarks ?? "Follows {$employee->emp_name}'s reporting change.",
                    $updatedBy,
                )) {
                    $changed->push($descendantId);
                }
            }

            return $changed;
        });
    }

    private function canHaveBoss(?int $designation): bool
    {
        return Employee::designationRank($designation) > 0
            && $designation !== Employee::DESIGNATION_BUSINESS_HEAD;
    }

    /**
     * @param  array<string, int|null>  $columns
     */
    private function applyColumns(
        Employee $employee,
        array $columns,
        string $effectiveDate,
        string $changeType,
        ?string $remarks,
        ?int $updatedBy,
    ): bool {
        $old = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $old[$column] = $employee->{$column} === null ? null : (int) $employee->{$column};
        }

        if ($old === $columns) {
            return false;
        }

        $employee->forceFill($columns)->save();

        if (strtolower(trim((string) $employee->exit_status)) === 'yes') {
            return true;
        }

        EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => $effectiveDate,
                'updated_at' => now(),
            ]);

        EmployeeReportingHistory::query()->create([
            'employee_id' => $employee->id,
            'old_superviser_id' => $old['superviser_id'],
            'old_manager_id' => $old['manager_id'],
            'old_cluster_id' => $old['cluster_id'],
            'old_business_head_id' => $old['business_head_id'],
            'new_superviser_id' => $columns['superviser_id'],
            'new_manager_id' => $columns['manager_id'],
            'new_cluster_id' => $columns['cluster_id'],
            'new_business_head_id' => $columns['business_head_id'],
            'effective_date' => $effectiveDate,
            'change_type' => $changeType,
            'updated_by' => $updatedBy,
            'remarks' => $remarks,
        ]);

        return true;
    }
}
