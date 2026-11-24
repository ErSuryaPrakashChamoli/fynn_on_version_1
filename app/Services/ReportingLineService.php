<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Support\ReportingTree;
use App\Support\TargetCategoryTimeline;
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
 *
 * The effective date matters: every month-based figure (targets,
 * achievement, incentives, team listings) follows the reporting line of
 * each day, rebuilt from the history rows written here (ReportingTimeline),
 * so a change only moves somebody from its effective date onward. A
 * promotion or demotion is dated the same way (recordDesignationChange()):
 * the months before it keep the designation held then, and with it the
 * team that reported to them through that designation's column.
 */
class ReportingLineService
{
    public const CHANGE_REPORTING = 'reporting_change';

    public const CHANGE_TRANSFER = 'transfer';

    /** A promotion or demotion: carries old/new_designation, never moves anybody. */
    public const CHANGE_DESIGNATION = 'designation';

    /** A target category change: carries old/new_category, never moves anybody. */
    public const CHANGE_TARGET_CATEGORY = 'target_category';

    /**
     * Rows that date something other than the reporting line, so a move
     * neither ends nor rebuilds from them.
     *
     * @var list<string>
     */
    public const NON_REPORTING_CHANGES = ['exit', self::CHANGE_DESIGNATION, self::CHANGE_TARGET_CATEGORY];

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
     * A history row is written for every employee whose columns actually
     * changed — an exited employee too: their cases can still disburse
     * after they leave, and ReportingTimeline rebuilds past months from
     * these rows, so an unrecorded move would restate every month since
     * their last recorded line. Their exit row is left as it is.
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

    /**
     * Date a promotion or demotion that has already been saved on the
     * employee, so months before $effectiveDate are still rebuilt with
     * $previousDesignation (ReportingTimeline). Call it alongside the
     * reportTo() calls that re-slot their team, with the same date.
     */
    public function recordDesignationChange(
        Employee $employee,
        int $previousDesignation,
        CarbonInterface|string|null $effectiveDate = null,
        ?string $remarks = null,
        ?int $updatedBy = null,
    ): void {
        $designation = (int) $employee->designation;

        if ($designation === $previousDesignation) {
            return;
        }

        $effectiveDate = Carbon::parse($effectiveDate ?? today())->toDateString();

        EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', self::CHANGE_DESIGNATION)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => $effectiveDate,
                'updated_at' => now(),
            ]);

        $columns = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $columns['old_'.$column] = $employee->{$column};
            $columns['new_'.$column] = $employee->{$column};
        }

        EmployeeReportingHistory::query()->create([
            'employee_id' => $employee->id,
            ...$columns,
            'old_designation' => $previousDesignation,
            'new_designation' => $designation,
            'effective_date' => $effectiveDate,
            'change_type' => self::CHANGE_DESIGNATION,
            'updated_by' => $updatedBy,
            'remarks' => $remarks,
        ]);
    }

    /**
     * Date a target category change that has already been saved on the
     * employee, so months before $effectiveDate keep $previousCategory's
     * target (TargetCategoryTimeline) and the month it falls in is split by
     * days.
     */
    public function recordCategoryChange(
        Employee $employee,
        ?string $previousCategory,
        CarbonInterface|string|null $effectiveDate = null,
        ?string $remarks = null,
        ?int $updatedBy = null,
    ): void {
        $category = filled($employee->category) ? (string) $employee->category : null;
        $previousCategory = filled($previousCategory) ? $previousCategory : null;

        if ($category === $previousCategory) {
            return;
        }

        $effectiveDate = Carbon::parse($effectiveDate ?? today())->toDateString();

        EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', self::CHANGE_TARGET_CATEGORY)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => $effectiveDate,
                'updated_at' => now(),
            ]);

        $columns = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $columns['old_'.$column] = $employee->{$column};
            $columns['new_'.$column] = $employee->{$column};
        }

        EmployeeReportingHistory::query()->create([
            'employee_id' => $employee->id,
            ...$columns,
            'old_category' => $previousCategory,
            'new_category' => $category,
            'effective_date' => $effectiveDate,
            'change_type' => self::CHANGE_TARGET_CATEGORY,
            'updated_by' => $updatedBy,
            'remarks' => $remarks,
        ]);

        TargetCategoryTimeline::forget();
    }

    /**
     * Whether a reporting-line history row put the employee under somebody
     * else. A row that kept the very same people above them — only re-filed
     * into other columns after a boss's designation change ("Follows X's
     * designation change."), or that changed nothing at all — did not: it
     * neither blocks an earlier-dated change nor survives one
     * (dropSupersededLines()).
     */
    public static function movesEmployee(object $row): bool
    {
        if ($row->change_type === 'joining') {
            return true;
        }

        return self::bossIdsOf($row, 'old_') !== self::bossIdsOf($row, 'new_');
    }

    /**
     * Remove $employeeId's reporting-line rows dated on or after $date that
     * did not move them (movesEmployee()). A change dated $date sets their
     * line from that day, and these rows, left behind it, would put back
     * the columns of the position they held before — so a promotion back-
     * dated past a "Follows X's designation change." row is not undone by
     * it from that row's date. Rows in $exceptIds are kept.
     *
     * @param  list<int>  $exceptIds
     * @return int how many rows were removed
     */
    public function dropSupersededLines(int $employeeId, CarbonInterface|string $date, array $exceptIds = []): int
    {
        $ids = EmployeeReportingHistory::query()
            ->toBase()
            ->where('employee_id', $employeeId)
            ->whereNotIn('change_type', [...self::NON_REPORTING_CHANGES, 'joining'])
            ->whereDate('effective_date', '>=', Carbon::parse($date)->toDateString())
            ->whereNotIn('id', $exceptIds)
            ->get()
            ->reject(fn (object $row): bool => self::movesEmployee($row))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            EmployeeReportingHistory::query()->whereKey($ids)->delete();
        }

        return count($ids);
    }

    /**
     * Re-link the effective_to of $employeeId's history once rows were
     * re-dated or removed: within each kind (reporting line, designation,
     * target category) a row ends where the next one starts, and the latest
     * stays open — or ends on the exit date of somebody who has left, as
     * EmployeeInactivationService closes it. Shown on the lifecycle; every
     * figure reads effective_date only.
     */
    public function resequenceHistory(int $employeeId): void
    {
        $employee = Employee::query()->find($employeeId, ['id', 'exit_status', 'exit_date']);
        $exitDate = $employee && strtolower(trim((string) $employee->exit_status)) === 'yes' && filled($employee->exit_date)
            ? Carbon::parse($employee->exit_date)->toDateString()
            : null;

        EmployeeReportingHistory::query()
            ->where('employee_id', $employeeId)
            ->where('change_type', '!=', 'exit')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (EmployeeReportingHistory $row): string => in_array($row->change_type, [self::CHANGE_DESIGNATION, self::CHANGE_TARGET_CATEGORY], true)
                ? $row->change_type
                : 'line')
            ->each(function (Collection $chain) use ($exitDate): void {
                $chain = $chain->values();

                foreach ($chain as $index => $row) {
                    $next = $chain->get($index + 1);
                    $effectiveTo = $next ? $next->effective_date->toDateString() : $exitDate;

                    if ($row->effective_to?->toDateString() !== $effectiveTo) {
                        $row->forceFill(['effective_to' => $effectiveTo])->save();
                    }
                }
            });
    }

    /**
     * The distinct people a row's $prefix ('old_' / 'new_') columns put
     * above the employee, whatever column each sits in.
     *
     * @return list<int>
     */
    private static function bossIdsOf(object $row, string $prefix): array
    {
        $ids = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $value = $row->{$prefix.$column} ?? null;

            if ($value !== null && (int) $value !== 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        sort($ids);

        return $ids;
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

        // A move ends the previous reporting line, never a designation or
        // a target category.
        EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('change_type', self::NON_REPORTING_CHANGES)
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
