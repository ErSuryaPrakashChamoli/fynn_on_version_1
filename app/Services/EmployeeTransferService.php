<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HierarchyTransferLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves employees to a new boss one by one — from the "Transfer Employee"
 * action and the Flexible Reassignment — only once nothing is attached to
 * them (EmployeeDependencyService). They move alone: their team, open
 * customers, leads and delegations are handed over first.
 *
 * A Full Cluster Transfer is the deliberate exception: it moves whole teams
 * together (HierarchyReassignmentService::transfer()), so nothing is left
 * behind for it to check.
 */
class EmployeeTransferService
{
    public function __construct(
        private EmployeeDependencyService $dependencies,
        private ReportingLineService $reportingLines,
        private HierarchyReassignmentService $reassignments,
    ) {}

    /**
     * Move $employee alone to $newBossId from $effectiveDate.
     *
     * @throws ValidationException while anything is still attached, the date
     *                             is in the future, or the boss is not allowed
     */
    public function transfer(Employee $employee, int $newBossId, CarbonInterface $effectiveDate, ?string $remarks, ?User $performedBy): void
    {
        if ($effectiveDate->copy()->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages(['effective_date' => 'The effective date cannot be in the future.']);
        }

        DB::transaction(function () use ($employee, $newBossId, $effectiveDate, $remarks, $performedBy): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $this->dependencies->assertNothingAttached($employee, 'reports_to');

            // reporting_date is deliberately left untouched (see
            // ReportingLineService): a transfer must not make them look like
            // a new joiner. The effective date dates the history entries.
            $this->reportingLines->reportTo(
                $employee,
                $newBossId,
                $effectiveDate,
                ReportingLineService::CHANGE_TRANSFER,
                filled($remarks) ? $remarks : 'Transferred from Employee Edit.',
                $performedBy?->id,
            );
        });
    }

    /**
     * A Flexible Reassignment, refused while anybody in it still has
     * something attached. Rows run lowest level first, so a caller moved
     * away from their Team Leader in the same batch has gone before the
     * Team Leader moves.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     */
    public function reassign(array $assignments, int $performedBy, ?string $effectiveDate = null, ?string $remarks = null): HierarchyTransferLog
    {
        return DB::transaction(function () use ($assignments, $performedBy, $effectiveDate, $remarks): HierarchyTransferLog {
            $blockers = $this->blockers($assignments);

            if ($blockers !== []) {
                $employeeId = array_key_first($blockers);
                $name = Employee::query()->whereKey($employeeId)->value('emp_name');

                throw ValidationException::withMessages([
                    'assignments' => "{$name} still has {$this->dependencies->describe($blockers[$employeeId])}. Hand them over to somebody else first.",
                ]);
            }

            $designations = Employee::query()
                ->whereIn('id', $this->movingIds($assignments))
                ->pluck('designation', 'id');

            $rows = collect($assignments)
                ->values()
                ->sortBy(fn (array $row): int => Employee::designationRank($designations[(int) ($row['employee_id'] ?? 0)] ?? null))
                ->values()
                ->all();

            return $this->reassignments->reassign($rows, $performedBy, $effectiveDate, $remarks);
        });
    }

    /**
     * Everybody in a Flexible Reassignment who still has something attached
     * that is not moving with the batch, in the order they were entered.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     * @return array<int, array<string, int>> employee id => what is still attached
     */
    public function blockers(array $assignments): array
    {
        $blockers = [];

        foreach (Employee::query()->whereIn('id', $this->movingIds($assignments))->get()->keyBy('id') as $employeeId => $employee) {
            $counts = $this->dependencies->dependencyCounts($employee, $this->alsoMovingIds($assignments, $employeeId));

            if ($counts !== []) {
                $blockers[$employeeId] = $counts;
            }
        }

        return collect($this->movingIds($assignments))
            ->filter(fn (int $id): bool => isset($blockers[$id]))
            ->mapWithKeys(fn (int $id): array => [$id => $blockers[$id]])
            ->all();
    }

    /**
     * The people the batch moves away from $employeeId — their direct
     * reports among them are not left behind.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     * @return list<int>
     */
    public function alsoMovingIds(array $assignments, int $employeeId): array
    {
        return collect($assignments)
            ->filter(fn (array $row): bool => filled($row['employee_id'] ?? null)
                && filled($row['target_id'] ?? null)
                && (int) $row['target_id'] !== $employeeId)
            ->map(fn (array $row): int => (int) $row['employee_id'])
            ->reject(fn (int $id): bool => $id === $employeeId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     * @return list<int>
     */
    private function movingIds(array $assignments): array
    {
        return collect($assignments)
            ->pluck('employee_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
