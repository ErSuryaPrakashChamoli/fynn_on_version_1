<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes an employee off the rolls (employees.exit_status / exit_date) — but
 * only once nothing is attached to them any more (EmployeeDependencyService).
 *
 * exit_date is the effective date every calculation reads
 * (AchievementCalculatorService, TeamPerformanceService,
 * Employee::scopeActiveDuring), so it is always recorded.
 */
class EmployeeInactivationService
{
    public function __construct(private EmployeeDependencyService $dependencies) {}

    /**
     * Take $employee off the rolls from $effectiveDate.
     *
     * @throws ValidationException when anything is still attached to them,
     *                             they have already left, or the date is in
     *                             the future or before they joined
     */
    public function inactivate(Employee $employee, CarbonInterface $effectiveDate, ?string $remarks, ?User $performedBy): void
    {
        $exitDate = Carbon::parse($effectiveDate)->startOfDay();

        if ($exitDate->isAfter(today())) {
            $this->fail('exit_date', 'The effective date cannot be in the future.');
        }

        if (filled($employee->doj) && $exitDate->lt(Carbon::parse($employee->doj)->startOfDay())) {
            $this->fail('exit_date', 'The effective date cannot be before they joined on '.Carbon::parse($employee->doj)->format('d M Y').'.');
        }

        DB::transaction(function () use ($employee, $exitDate, $remarks, $performedBy): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (strtolower(trim((string) $employee->exit_status)) === 'yes') {
                $this->fail('exit_date', "{$employee->emp_name} is already inactive.");
            }

            $this->dependencies->assertNothingAttached($employee, 'dependencies');

            $employee->forceFill([
                'exit_status' => 'yes',
                'exit_date' => $exitDate->toDateString(),
            ])->save();

            EmployeeReportingHistory::query()
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => $exitDate->toDateString(),
                    'updated_at' => now(),
                ]);

            EmployeeReportingHistory::query()->create([
                'employee_id' => $employee->id,
                'old_superviser_id' => $employee->superviser_id,
                'old_manager_id' => $employee->manager_id,
                'old_cluster_id' => $employee->cluster_id,
                'old_business_head_id' => $employee->business_head_id,
                'new_superviser_id' => null,
                'new_manager_id' => null,
                'new_cluster_id' => null,
                'new_business_head_id' => null,
                'effective_date' => $exitDate->toDateString(),
                'change_type' => 'exit',
                'updated_by' => $performedBy?->id,
                'remarks' => filled($remarks) ? trim($remarks) : 'Employee exited.',
            ]);
        });

        app(MonthlyTargetGate::class)->forget();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
