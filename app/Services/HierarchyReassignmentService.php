<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HierarchyTransferLog;
use App\Support\ReportingTree;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class HierarchyReassignmentService
{
    public function __construct(private ReportingLineService $reportingLines) {}

    /**
     * Transfer employees from one Cluster Manager to another.
     *
     * Moves the source Cluster Manager's direct reports — every one of them
     * for a full cluster transfer, or only the selected Managers and Team
     * Leaders — to the target. ReportingLineService re-fills the columns of
     * everyone below each moved employee, so their teams go with them.
     *
     * @param array{
     *     source_cluster_manager_id:int|string,
     *     target_cluster_manager_id:int|string,
     *     transfer_type:'full_cluster'|'selective',
     *     selected_employee_ids?:array<int|string>,
     *     effective_date?:string,
     *     remarks?:string|null
     * } $data
     */
    public function transfer(array $data, int $performedBy): HierarchyTransferLog
    {
        return DB::transaction(function () use ($data, $performedBy): HierarchyTransferLog {
            $sourceId = (int) ($data['source_cluster_manager_id'] ?? 0);
            $targetId = (int) ($data['target_cluster_manager_id'] ?? 0);
            $transferType = (string) ($data['transfer_type'] ?? '');
            $effectiveDate = Carbon::parse($data['effective_date'] ?? now()->toDateString())->startOfDay();
            $remarks = filled($data['remarks'] ?? null) ? trim((string) $data['remarks']) : null;
            $selectedIds = collect($data['selected_employee_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($sourceId <= 0 || $targetId <= 0) {
                $this->validationError('source_cluster_manager_id', 'Both source and target Cluster Managers are required.');
            }

            if ($sourceId === $targetId) {
                $this->validationError('target_cluster_manager_id', 'Source and target Cluster Managers must be different.');
            }

            if (! in_array($transferType, ['full_cluster', 'selective'], true)) {
                $this->validationError('transfer_type', 'Invalid transfer scope selected.');
            }

            /** @var Employee|null $source */
            $source = Employee::query()
                ->whereKey($sourceId)
                ->where('designation', Employee::DESIGNATION_CLUSTER)
                ->lockForUpdate()
                ->first();

            /** @var Employee|null $target */
            $target = Employee::query()
                ->whereKey($targetId)
                ->where('designation', Employee::DESIGNATION_CLUSTER)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                $this->validationError('source_cluster_manager_id', 'The selected source Cluster Manager does not exist or is not a Cluster Manager.');
            }

            if (! $target) {
                $this->validationError('target_cluster_manager_id', 'The selected destination Cluster Manager does not exist or is not a Cluster Manager.');
            }

            if ($source->exit_status === 'yes') {
                $this->validationError('source_cluster_manager_id', 'The source Cluster Manager is inactive/exited and cannot be transferred.');
            }

            if ($target->exit_status === 'yes') {
                $this->validationError('target_cluster_manager_id', 'The destination Cluster Manager is inactive/exited and cannot receive the transfer.');
            }

            if ($effectiveDate->isFuture()) {
                $this->validationError('effective_date', 'Effective date cannot be in the future.');
            }

            $movedEmployees = $transferType === 'full_cluster'
                ? $this->fullClusterReports($sourceId)
                : $this->selectedReports($sourceId, $selectedIds);

            if ($movedEmployees->isEmpty()) {
                $this->validationError('selected_employee_ids', 'There are no employees reporting to the source Cluster Manager to transfer.');
            }

            // reporting_date is deliberately left untouched (see
            // ReportingLineService): a hierarchy transfer moves existing
            // employees and must not make them look like new joiners.
            $historyRemarks = $remarks ?: "Cluster transfer from {$source->emp_name} to {$target->emp_name}.";

            $affectedIds = $movedEmployees
                ->flatMap(fn (Employee $employee): Collection => $this->reportingLines->reportTo(
                    $employee,
                    $targetId,
                    $effectiveDate,
                    ReportingLineService::CHANGE_TRANSFER,
                    $historyRemarks,
                    $performedBy,
                ))
                ->merge($movedEmployees->pluck('id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            /** @var HierarchyTransferLog $log */
            $log = HierarchyTransferLog::query()->create([
                'source_cluster_manager_id' => $sourceId,
                'target_cluster_manager_id' => $targetId,
                'transfer_type' => $transferType,
                'selected_employee_ids' => $selectedIds->all(),
                'affected_employee_ids' => $affectedIds->all(),
                'affected_count' => $affectedIds->count(),
                'effective_date' => $effectiveDate->toDateString(),
                'performed_by' => $performedBy,
                'remarks' => $remarks,
            ]);

            $this->writeActivityLogIfAvailable($log, $source, $target, $affectedIds, $transferType, $remarks);

            return $log->load(['sourceClusterManager', 'targetClusterManager', 'performedBy']);
        }, 3);
    }

    /**
     * Move a boss's team — every direct report, or only the ones picked —
     * to another boss while the boss stays where they are. Any level: a
     * Team Leader's callers, a Manager's Team Leaders, a Cluster Manager's
     * Managers. Each moved employee takes everyone below them, and all their
     * own work.
     *
     * The team is everybody on the rolls reporting straight to the boss,
     * plus any who have left but still have people on the rolls below them,
     * so those people are not split from their team (as a Full Cluster
     * Transfer does).
     *
     * @param  list<int>|null  $memberIds  null moves the whole team
     */
    public function moveTeam(Employee $boss, ?array $memberIds, int $newBossId, CarbonInterface|string $effectiveDate, ?string $remarks, int $performedBy): HierarchyTransferLog
    {
        return DB::transaction(function () use ($boss, $memberIds, $newBossId, $effectiveDate, $remarks, $performedBy): HierarchyTransferLog {
            $effective = Carbon::parse($effectiveDate)->startOfDay();
            $remarks = filled($remarks) ? trim((string) $remarks) : null;

            if ($effective->isFuture()) {
                $this->validationError('effective_date', 'Effective date cannot be in the future.');
            }

            if ($newBossId === $boss->id) {
                $this->validationError('target_id', "{$boss->emp_name}'s team already reports to them. Choose somebody else.");
            }

            $tree = ReportingTree::load();
            $teamIds = $this->teamIds($tree, $boss->id);

            if ($memberIds === null) {
                $moveIds = $teamIds;
            } else {
                $moveIds = array_values(array_unique(array_map('intval', $memberIds)));

                if ($moveIds === []) {
                    $this->validationError('member_ids', 'Pick at least one person to move.');
                }

                if (array_diff($moveIds, $teamIds) !== []) {
                    $this->validationError('member_ids', "Somebody picked no longer reports to {$boss->emp_name}. Open the pop-up again and pick again.");
                }
            }

            if ($moveIds === []) {
                $this->validationError('member_ids', "Nobody reports to {$boss->emp_name}.");
            }

            if (in_array($newBossId, $tree->descendantIds($boss->id), true)) {
                $this->validationError('target_id', "The new boss cannot be somebody in {$boss->emp_name}'s own team.");
            }

            $target = Employee::query()->whereKey($newBossId)->lockForUpdate()->first();

            if (! $target) {
                $this->validationError('target_id', 'The selected new boss no longer exists.');
            }

            $members = Employee::query()->whereIn('id', $moveIds)->lockForUpdate()->get();

            // Worked out before anything moves, so the log records where
            // the team came from.
            $sourceCluster = $this->clusterOf($tree, $boss->id);
            $targetCluster = $this->clusterOf($tree, $target->id);

            $historyRemarks = $remarks ?: "Moved from {$boss->emp_name}'s team to {$target->emp_name}.";

            // reporting_date is deliberately left untouched: the team moves
            // as existing employees, not new joiners.
            $affectedIds = $members
                ->flatMap(fn (Employee $member): Collection => $this->reportingLines->reportTo(
                    $member,
                    $target->id,
                    $effective,
                    ReportingLineService::CHANGE_TRANSFER,
                    $historyRemarks,
                    $performedBy,
                ))
                ->merge($members->pluck('id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            /** @var HierarchyTransferLog $log */
            $log = HierarchyTransferLog::query()->create([
                'source_cluster_manager_id' => $sourceCluster,
                'target_cluster_manager_id' => $targetCluster,
                'transfer_type' => $memberIds === null ? 'whole_team' : 'part_team',
                'selected_employee_ids' => ['from' => $boss->id, 'to' => $target->id, 'members' => $members->pluck('id')->all()],
                'affected_employee_ids' => $affectedIds->all(),
                'affected_count' => $affectedIds->count(),
                'effective_date' => $effective->toDateString(),
                'performed_by' => $performedBy,
                'remarks' => $remarks,
            ]);

            if (function_exists('activity')) {
                try {
                    activity('hierarchy')
                        ->causedBy(auth()->user())
                        ->performedOn($log)
                        ->withProperties([
                            'from_boss_id' => $boss->id,
                            'to_boss_id' => $target->id,
                            'member_ids' => $members->pluck('id')->all(),
                            'affected_count' => $affectedIds->count(),
                            'remarks' => $remarks,
                        ])
                        ->log($memberIds === null ? 'Whole team moved' : 'Part of a team moved');
                } catch (Throwable) {
                    // Dedicated transfer log remains authoritative.
                }
            }

            return $log;
        }, 3);
    }

    /**
     * Flexible reassignment: move individual employees to anyone more
     * senior who is still on the rolls. Levels may be skipped — a caller
     * straight to a Manager, a Team Leader straight to a Cluster Manager —
     * and each row may have a different destination, so one team can be
     * split across several bosses. Everyone below a moved employee follows
     * them.
     *
     * @param  array<int, array{employee_id:int|string,target_id:int|string}>  $assignments
     */
    public function reassign(array $assignments, int $performedBy, ?string $effectiveDate = null, ?string $remarks = null): HierarchyTransferLog
    {
        return DB::transaction(function () use ($assignments, $performedBy, $effectiveDate, $remarks): HierarchyTransferLog {
            $effective = Carbon::parse($effectiveDate ?? now()->toDateString())->startOfDay();

            if ($effective->isFuture()) {
                $this->validationError('effective_date', 'Effective date cannot be in the future.');
            }

            $rows = collect($assignments)
                ->map(fn ($row): array => [
                    'employee_id' => (int) ($row['employee_id'] ?? 0),
                    'target_id' => (int) ($row['target_id'] ?? 0),
                ])
                ->filter(fn (array $row): bool => $row['employee_id'] > 0 && $row['target_id'] > 0)
                ->unique('employee_id')
                ->values();

            if ($rows->isEmpty()) {
                $this->validationError('assignments', 'Add at least one employee and destination.');
            }

            $employeeIds = $rows->pluck('employee_id');
            $targetIds = $rows->pluck('target_id')->unique()->values();

            $employees = Employee::query()
                ->whereIn('id', $employeeIds)
                ->where('exit_status', '!=', 'yes')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($employees->count() !== $employeeIds->count()) {
                $this->validationError('assignments', 'One or more selected employees are inactive or no longer exist. Reopen the transfer form and try again.');
            }

            $targets = Employee::query()
                ->whereIn('id', $targetIds)
                ->where('exit_status', '!=', 'yes')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($targets->count() !== $targetIds->count()) {
                $this->validationError('assignments', 'One or more selected destinations are inactive or no longer exist.');
            }

            $tree = ReportingTree::load();

            foreach ($rows as $row) {
                $employee = $employees->get($row['employee_id']);
                $target = $targets->get($row['target_id']);

                if ($employee->id === $target->id) {
                    $this->validationError('assignments', "{$employee->emp_name} cannot be moved to itself.");
                }

                if ($tree->bossId($employee->id) === $target->id) {
                    $this->validationError('assignments', "{$employee->emp_name} already reports to {$target->emp_name}.");
                }

                $problem = $this->reportingLines->bossProblem($employee->designation, $target->id, $employee->id);

                if ($problem !== null) {
                    $this->validationError('assignments', "{$employee->emp_name}: {$problem}");
                }
            }

            // Worked out before anything moves, so the log records where
            // the employees came from.
            $sourceClusters = $employees->keys()->map(fn (int $id): ?int => $this->clusterOf($tree, $id))->filter()->unique()->values();
            $targetClusters = $targets->keys()->map(fn (int $id): ?int => $this->clusterOf($tree, $id))->filter()->unique()->values();

            $affectedIds = $rows
                ->flatMap(function (array $row) use ($employees, $targets, $effective, $remarks, $performedBy): Collection {
                    $target = $targets->get($row['target_id']);

                    // reporting_date is deliberately left untouched: a
                    // flexible reassignment moves an existing, active
                    // employee — it must not make them a new joiner.
                    return $this->reportingLines->reportTo(
                        $employees->get($row['employee_id']),
                        $target->id,
                        $effective,
                        ReportingLineService::CHANGE_TRANSFER,
                        $remarks ?: "Flexible hierarchy reassignment to {$target->emp_name}.",
                        $performedBy,
                    );
                })
                ->merge($employeeIds)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            /** @var HierarchyTransferLog $log */
            $log = HierarchyTransferLog::query()->create([
                'source_cluster_manager_id' => $sourceClusters->count() === 1 ? $sourceClusters->first() : null,
                'target_cluster_manager_id' => $targetClusters->count() === 1 ? $targetClusters->first() : null,
                'transfer_type' => 'flexible_reassignment',
                'selected_employee_ids' => $rows->all(),
                'affected_employee_ids' => $affectedIds->all(),
                'affected_count' => $affectedIds->count(),
                'effective_date' => $effective->toDateString(),
                'performed_by' => $performedBy,
                'remarks' => $remarks,
            ]);

            if (function_exists('activity')) {
                try {
                    activity('hierarchy')
                        ->causedBy(auth()->user())
                        ->performedOn($log)
                        ->withProperties([
                            'assignments' => $rows->all(),
                            'affected_count' => $affectedIds->count(),
                            'remarks' => $remarks,
                        ])
                        ->log('Flexible hierarchy reassignment completed');
                } catch (Throwable) {
                    // Dedicated transfer log remains authoritative.
                }
            }

            return $log;
        }, 3);
    }

    /**
     * Full cluster: everybody reporting straight to the source Cluster
     * Manager, at whatever level — including those who have left, so the
     * people still working under them are not split from their team.
     *
     * @return Collection<int, Employee>
     */
    private function fullClusterReports(int $sourceId): Collection
    {
        return Employee::query()
            ->whereIn('id', ReportingTree::load()->childIds($sourceId))
            ->lockForUpdate()
            ->get();
    }

    /**
     * Selective transfer: the chosen Managers and Team Leaders reporting
     * straight to the source Cluster Manager, each taking their team along.
     *
     * @param  Collection<int, int>  $selectedIds
     * @return Collection<int, Employee>
     */
    private function selectedReports(int $sourceId, Collection $selectedIds): Collection
    {
        if ($selectedIds->isEmpty()) {
            $this->validationError('selected_employee_ids', 'Select at least one Manager or Team Leader.');
        }

        $selected = Employee::query()
            ->whereIn('id', $selectedIds)
            ->where('exit_status', '!=', 'yes')
            ->lockForUpdate()
            ->get();

        if ($selectedIds->diff($selected->pluck('id'))->isNotEmpty()) {
            $this->validationError('selected_employee_ids', 'One or more selected employees are inactive or no longer exist. Please reopen the transfer form and select again.');
        }

        $invalidDesignation = $selected->contains(
            fn (Employee $employee): bool => ! in_array(
                $employee->designation,
                [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER],
                true
            )
        );

        if ($invalidDesignation) {
            $this->validationError('selected_employee_ids', 'Only Managers and Team Leaders can be selected for a selective cluster transfer.');
        }

        $tree = ReportingTree::load();

        if ($selected->contains(fn (Employee $employee): bool => $tree->bossId($employee->id) !== $sourceId)) {
            $this->validationError('selected_employee_ids', 'One or more selected employees no longer report to the selected source Cluster Manager.');
        }

        return $selected;
    }

    /**
     * Who a team move takes: direct reports on the rolls, and those who
     * have left but still have somebody on the rolls below them.
     *
     * @return list<int>
     */
    private function teamIds(ReportingTree $tree, int $bossId): array
    {
        return array_values(array_filter(
            $tree->childIds($bossId),
            fn (int $id): bool => ! $tree->isExited($id)
                || array_filter($tree->descendantIds($id), fn (int $belowId): bool => ! $tree->isExited($belowId)) !== [],
        ));
    }

    /** The Cluster Manager an employee sits under — or is. */
    private function clusterOf(ReportingTree $tree, int $employeeId): ?int
    {
        return $tree->designation($employeeId) === Employee::DESIGNATION_CLUSTER
            ? $employeeId
            : $tree->nearestAncestorId($employeeId, [Employee::DESIGNATION_CLUSTER]);
    }

    private function validationError(string $key, string $message): never
    {
        throw ValidationException::withMessages([
            $key => $message,
        ]);
    }

    private function writeActivityLogIfAvailable(
        HierarchyTransferLog $log,
        Employee $source,
        Employee $target,
        Collection $affectedIds,
        string $transferType,
        ?string $remarks,
    ): void {
        // Spatie Activity Log is installed in this project. Keep the module
        // independent from it so the hierarchy transfer remains functional if
        // activity logging is disabled in another environment.
        if (! function_exists('activity')) {
            return;
        }

        try {
            activity('hierarchy')
                ->causedBy(auth()->user())
                ->performedOn($log)
                ->withProperties([
                    'source_cluster_manager_id' => $source->id,
                    'target_cluster_manager_id' => $target->id,
                    'transfer_type' => $transferType,
                    'affected_employee_ids' => $affectedIds->all(),
                    'affected_count' => $affectedIds->count(),
                    'remarks' => $remarks,
                ])
                ->log('Cluster hierarchy transferred');
        } catch (Throwable) {
            // The dedicated hierarchy_transfer_logs table is the authoritative
            // audit record. Activity logging must never make a successful
            // transfer fail.
        }
    }
}
