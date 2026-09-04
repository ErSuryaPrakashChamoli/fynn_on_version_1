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
}
