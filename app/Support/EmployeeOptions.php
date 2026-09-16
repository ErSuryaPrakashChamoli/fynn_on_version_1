<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\Auth;

/**
 * Shared option lists for the employee dropdowns used by table filters.
 *
 * Every label carries the employee ID alongside the name ("Asha Rao (FYN-0142)")
 * so two people with the same name can still be told apart, matching the
 * format already used on the employee management screens.
 */
class EmployeeOptions
{
    /**
     * Every employee holding the given designation, keyed by employee id.
     *
     * @return array<int, string>
     */
    public static function forDesignation(int $designation): array
    {
        return Employee::query()
            ->where('designation', $designation)
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => self::label($employee),
            ])
            ->all();
    }

    /**
     * Every employee the given user is allowed to see, keyed by employee id.
     *
     * @return array<int, string>
     */
    public static function visibleTo(?User $user = null): array
    {
        $user ??= Auth::user();

        if (! $user) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', HierarchyService::visibleEmployeeIds($user))
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => self::label($employee),
            ])
            ->all();
    }

    public static function label(Employee $employee): string
    {
        return filled($employee->emp_id)
            ? "{$employee->emp_name} ({$employee->emp_id})"
            : (string) $employee->emp_name;
    }

    /**
     * The same label plus whoever this employee reports to — "Asha Rao
     * (FYN-0142) - Reports to: Nitin Thakur (Team Leader)" — so a name picked
     * from a dropdown can be placed in the hierarchy without opening the
     * employee record.
     *
     * Pass a ReportingTree when labelling a list: building one costs a single
     * query, and leaving it out loads a fresh tree for every row.
     */
    public static function labelWithReportingLine(Employee $employee, ?ReportingTree $tree = null): string
    {
        return self::label($employee).' - Reports to: '.(self::reportingBossLabel($employee, $tree) ?? 'Not assigned');
    }

    /**
     * The direct boss — "Nitin Thakur (Team Leader)" — at whatever level they
     * sit, so a skipped level reads as the Manager or Cluster Manager the
     * branch actually hangs off. Null when nobody is above them: a Business
     * Head, an out-of-tree seat such as Other Bank Support, or a broken
     * reporting line to fix.
     *
     * Resolved through ReportingTree so this agrees with every other
     * hierarchy walk: a reporting column is honoured only when it points at
     * an employee of that column's own level, because live data has callers
     * with a Manager, or even another Caller, in superviser_id.
     */
    public static function reportingBossLabel(Employee $employee, ?ReportingTree $tree = null): ?string
    {
        $tree ??= ReportingTree::load();

        $bossId = $tree->bossId($employee->id);

        if ($bossId === null) {
            return null;
        }

        $name = (string) $tree->name($bossId);
        $designation = Employee::designationOptions()[$tree->designation($bossId)] ?? null;

        return $designation !== null ? "{$name} ({$designation})" : $name;
    }
}
