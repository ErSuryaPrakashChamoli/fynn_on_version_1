<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\HierarchyTransferLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class HierarchyReassignmentService
{
    /**
     * Transfer employees from one Cluster Manager to another.
     *
     * The service deliberately changes only cluster_id. Existing
     * manager_id and superviser_id relationships are preserved.
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

            $affectedIds = $transferType === 'full_cluster'
                ? $this->resolveFullClusterEmployeeIds($sourceId)
                : $this->resolveSelectiveEmployeeIds($sourceId, $selectedIds);

            if ($affectedIds->isEmpty()) {
                $this->validationError('selected_employee_ids', 'There are no active employees eligible for this transfer.');
            }

            /**
             * Lock every affected employee before changing anything. This
             * protects against concurrent hierarchy edits while this batch runs.
             */
            $affectedEmployees = Employee::query()
                ->whereIn('id', $affectedIds)
                ->where('id', '!=', $sourceId)
                ->lockForUpdate()
                ->get();

            if ($affectedEmployees->isEmpty()) {
                $this->validationError('selected_employee_ids', 'No eligible employees were found after locking the selected hierarchy.');
            }

            $affectedIds = $affectedEmployees
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $this->closeOpenReportingHistory($affectedIds, $effectiveDate);

            $now = now();

            foreach ($affectedEmployees as $employee) {
                $oldClusterId = $employee->cluster_id;

                // Only the cluster assignment changes. The existing manager
                // and team-leader relationships remain intact.
                $employee->forceFill([
                    'cluster_id' => $targetId,
                    'reporting_date' => $effectiveDate->toDateString(),
                    'updated_at' => $now,
                ])->save();

                EmployeeReportingHistory::query()->create([
                    'employee_id' => $employee->id,
                    'old_superviser_id' => $employee->superviser_id,
                    'old_manager_id' => $employee->manager_id,
                    'old_cluster_id' => $oldClusterId,
                    'new_superviser_id' => $employee->superviser_id,
                    'new_manager_id' => $employee->manager_id,
                    'new_cluster_id' => $targetId,
                    'effective_date' => $effectiveDate->toDateString(),
                    'change_type' => 'transfer',
                    'updated_by' => $performedBy,
                    'remarks' => $remarks ?: "Cluster transfer from {$source->emp_name} to {$target->emp_name}.",
                ]);
            }

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
     * Flexible reassignment: move individual Callers to any active Team Leader
     * or individual Team Leaders to any active Manager. Each row may have a
     * different destination, so one manager's team can be split across several
     * managers without changing the overall hierarchy architecture.
     *
     * @param array<int, array{employee_id:int|string,target_id:int|string}> $assignments
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

            foreach ($rows as $row) {
                $employee = $employees->get($row['employee_id']);
                $target = $targets->get($row['target_id']);

                if ($employee->id === $target->id) {
                    $this->validationError('assignments', "{$employee->emp_name} cannot be moved to itself.");
                }

                if ($employee->designation === Employee::DESIGNATION_CALLER) {
                    if ($target->designation !== Employee::DESIGNATION_TEAM_LEADER) {
                        $this->validationError('assignments', "Caller {$employee->emp_name} can only be assigned to a Team Leader.");
                    }

                    if ((int) $employee->superviser_id === (int) $target->id) {
                        $this->validationError('assignments', "Caller {$employee->emp_name} is already under {$target->emp_name}.");
                    }

                    if (! $this->isActiveManager($target->manager_id)) {
                        $this->validationError('assignments', "The destination Team Leader {$target->emp_name} does not have an active Manager.");
                    }
                } elseif ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {
                    if ($target->designation !== Employee::DESIGNATION_MANAGER) {
                        $this->validationError('assignments', "Team Leader {$employee->emp_name} can only be assigned to a Manager.");
                    }

                    if ((int) $employee->manager_id === (int) $target->id) {
                        $this->validationError('assignments', "Team Leader {$employee->emp_name} is already under {$target->emp_name}.");
                    }
                } else {
                    $this->validationError('assignments', "{$employee->emp_name} cannot be moved through this flexible transfer. Only Callers and Team Leaders are supported.");
                }

                if ($target->designation === Employee::DESIGNATION_TEAM_LEADER && ! $this->isActiveManager($target->manager_id)) {
                    $this->validationError('assignments', "Destination Team Leader {$target->emp_name} has no active Manager.");
                }

                $targetCluster = $target->designation === Employee::DESIGNATION_MANAGER
                    ? $target->cluster_id
                    : $target->cluster_id;

                if (! $this->isActiveClusterManager($targetCluster)) {
                    $this->validationError('assignments', "Destination {$target->emp_name} belongs to an inactive or invalid Cluster Manager.");
                }
            }

            $affectedIds = $employeeIds->values();
            $this->closeOpenReportingHistory($affectedIds, $effective);

            $now = now();

            foreach ($rows as $row) {
                $employee = $employees->get($row['employee_id']);
                $target = $targets->get($row['target_id']);

                $oldSupervisorId = $employee->superviser_id;
                $oldManagerId = $employee->manager_id;
                $oldClusterId = $employee->cluster_id;

                if ($employee->designation === Employee::DESIGNATION_CALLER) {
                    $newSupervisorId = $target->id;
                    $newManagerId = $target->manager_id;
                } else {
                    $newSupervisorId = null;
                    $newManagerId = $target->id;
                }

                $employee->forceFill([
                    'superviser_id' => $newSupervisorId,
                    'manager_id' => $newManagerId,
                    'cluster_id' => $target->cluster_id,
                    'reporting_date' => $effective->toDateString(),
                    'updated_at' => $now,
                ])->save();

                EmployeeReportingHistory::query()->create([
                    'employee_id' => $employee->id,
                    'old_superviser_id' => $oldSupervisorId,
                    'old_manager_id' => $oldManagerId,
                    'old_cluster_id' => $oldClusterId,
                    'new_superviser_id' => $newSupervisorId,
                    'new_manager_id' => $newManagerId,
                    'new_cluster_id' => $target->cluster_id,
                    'effective_date' => $effective->toDateString(),
                    'change_type' => 'transfer',
                    'updated_by' => $performedBy,
                    'remarks' => $remarks ?: "Flexible hierarchy reassignment to {$target->emp_name}.",
                ]);
            }

            $sourceClusters = $employees->pluck('cluster_id')->filter()->unique()->values();
            $targetClusters = $targets->pluck('cluster_id')->filter()->unique()->values();

            $sourceClusterId = $sourceClusters->count() === 1 ? (int) $sourceClusters->first() : null;
            $targetClusterId = $targetClusters->count() === 1 ? (int) $targetClusters->first() : null;

            /** @var HierarchyTransferLog $log */
            $log = HierarchyTransferLog::query()->create([
                'source_cluster_manager_id' => $sourceClusterId,
                'target_cluster_manager_id' => $targetClusterId,
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

    private function isActiveManager(?int $managerId): bool
    {
        return $managerId !== null && Employee::query()
            ->whereKey($managerId)
            ->where('designation', Employee::DESIGNATION_MANAGER)
            ->where('exit_status', '!=', 'yes')
            ->exists();
    }

    private function isActiveClusterManager(?int $clusterId): bool
    {
        return $clusterId !== null && Employee::query()
            ->whereKey($clusterId)
            ->where('designation', Employee::DESIGNATION_CLUSTER)
            ->where('exit_status', '!=', 'yes')
            ->exists();
    }

    /**
     * Full cluster: transfer every active non-CM employee whose cluster_id
     * points at the source CM. This also safely catches active orphaned
     * hierarchy rows whose intermediate manager/TL relation is incomplete.
     */
    private function resolveFullClusterEmployeeIds(int $sourceId): Collection
    {
        return Employee::query()
            ->where('cluster_id', $sourceId)
            ->where('id', '!=', $sourceId)
            ->where('exit_status', '!=', 'yes')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Selective transfer accepts Managers and Team Leaders only. A Manager
     * selection transfers the Manager, its Team Leaders, their Callers and
     * any active employees below the selected branch. A Team Leader selection
     * transfers the Team Leader and its Callers.
     */
    private function resolveSelectiveEmployeeIds(int $sourceId, Collection $selectedIds): Collection
    {
        if ($selectedIds->isEmpty()) {
            $this->validationError('selected_employee_ids', 'Select at least one Manager or Team Leader.');
        }

        $selected = Employee::query()
            ->whereIn('id', $selectedIds)
            ->where('exit_status', '!=', 'yes')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $missingIds = $selectedIds->diff($selected->keys());

        if ($missingIds->isNotEmpty()) {
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

        $outsideSource = $selected->contains(
            fn (Employee $employee): bool => (int) $employee->cluster_id !== $sourceId
        );

        if ($outsideSource) {
            $this->validationError('selected_employee_ids', 'One or more selected employees no longer belong to the selected source Cluster Manager.');
        }

        $affected = collect();

        foreach ($selected as $employee) {
            $affected = $affected->merge($this->descendantIds($employee));
        }

        return $affected
            ->merge($selected->keys())
            ->unique()
            ->values();
    }

    /**
     * Walk the hierarchy by manager_id / superviser_id rather than trusting
     * only cluster_id. This makes selective transfers safe even if the data
     * contains incomplete intermediate relationships.
     */
    private function descendantIds(Employee $root): Collection
    {
        $ids = collect([$root->id]);
        $frontier = collect([$root->id]);

        while ($frontier->isNotEmpty()) {
            $managerIds = Employee::query()
                ->whereIn('manager_id', $frontier)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $superviserIds = Employee::query()
                ->whereIn('superviser_id', $frontier)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $children = $managerIds
                ->merge($superviserIds)
                ->map(fn ($id): int => (int) $id)
                ->diff($ids)
                ->unique()
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $ids = $ids->merge($children)->unique()->values();
            $frontier = $children;
        }

        // Selective branches must still be constrained to the source cluster.
        return Employee::query()
            ->whereIn('id', $ids)
            ->where('cluster_id', $root->cluster_id)
            ->where('exit_status', '!=', 'yes')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function closeOpenReportingHistory(Collection $employeeIds, Carbon $effectiveDate): void
    {
        EmployeeReportingHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => $effectiveDate->toDateString(),
                'updated_at' => now(),
            ]);
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
