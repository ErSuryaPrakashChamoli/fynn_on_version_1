<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may be found through the topbar "Find employee" search and opened
 * on the Employee Lookup page.
 *
 * The Admin finds everyone in the company, on the rolls or exited. Anybody
 * else finds themselves and their whole team down the line — never a boss,
 * a peer or another branch. The down-line is the visibility walk
 * (HierarchyHelper::visibleSubordinateIds), so the callers still working
 * under an exited Team Leader stay findable by their Manager.
 */
class EmployeeLookupAccess
{
    /**
     * Results returned per search; the dropdown is a picker, not a report.
     */
    public const SEARCH_LIMIT = 25;

    /**
     * @return Collection<int, int>|null null means "everyone" (the Admin)
     */
    public static function visibleIds(?User $user): ?Collection
    {
        if (! $user) {
            return collect();
        }

        if ($user->hasRole('Admin')) {
            return null;
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return HierarchyHelper::visibleSubordinateIds($employee);
    }

    /**
     * Whether the user has anybody at all to look up.
     */
    public static function canSearch(?User $user): bool
    {
        $visibleIds = self::visibleIds($user);

        return $visibleIds === null || $visibleIds->isNotEmpty();
    }

    public static function canView(?User $user, Employee $employee): bool
    {
        $visibleIds = self::visibleIds($user);

        return $visibleIds === null || $visibleIds->contains($employee->id);
    }

    /**
     * The employee, or null when they do not exist or the user may not
     * see them — the two cases are deliberately indistinguishable.
     */
    public static function find(?User $user, int|string|null $employeeId): ?Employee
    {
        if (blank($employeeId) || ! is_numeric($employeeId)) {
            return null;
        }

        $employee = Employee::query()->find((int) $employeeId);

        return $employee && self::canView($user, $employee) ? $employee : null;
    }

    /**
     * Employees whose name or employee ID contains the search term, the
     * user's own scope only, best matches first: an exact employee ID,
     * then names starting with the term, then everything else by name.
     *
     * @return Collection<int, Employee>
     */
    public static function search(?User $user, string $term, int $limit = self::SEARCH_LIMIT): Collection
    {
        $term = trim($term);
        $visibleIds = self::visibleIds($user);

        if ($term === '' || ($visibleIds !== null && $visibleIds->isEmpty())) {
            return collect();
        }

        // Not escaped: SQLite (the test suite) has no default LIKE escape
        // character, and a stray % or _ only widens results inside the
        // user's own scope.
        $like = "%{$term}%";
        $prefix = "{$term}%";

        return Employee::query()
            ->when($visibleIds !== null, fn ($query) => $query->whereIn('id', $visibleIds))
            ->where(fn ($query) => $query
                ->where('emp_name', 'like', $like)
                ->orWhere('emp_id', 'like', $like))
            ->orderByRaw('CASE WHEN emp_id = ? THEN 0 WHEN emp_name LIKE ? THEN 1 WHEN emp_id LIKE ? THEN 2 ELSE 3 END', [$term, $prefix, $prefix])
            ->orderBy('emp_name')
            ->limit($limit)
            ->get(['id', 'emp_id', 'emp_name', 'designation', 'exit_status']);
    }

    /**
     * The search results as dropdown options: "Asha Rao (FYN-0142)".
     *
     * @return array<int, string>
     */
    public static function searchOptions(?User $user, string $term): array
    {
        return self::search($user, $term)
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
            ->all();
    }

    public static function isExited(Employee $employee): bool
    {
        return strtolower(trim((string) $employee->exit_status)) === 'yes';
    }
}
