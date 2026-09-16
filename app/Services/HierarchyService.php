<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Support\HierarchyHelper;

class HierarchyService
{
    // public static function visibleEmployeeIds(User $user): array
    // {
    //     /*
    //     |--------------------------------------------------------------------------
    //     | ADMIN
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($user->hasRole('Admin')) {
    //         return Employee::pluck('id')->toArray();
    //     }

    //     $employee = $user->employee;

    //     if (! $employee) {
    //         return [];
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | CLUSTER HEAD
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($employee->designation === 'Cluster Manager') {

    //         return Employee::where('cluster_id', $employee->id)
    //             ->orWhere('id', $employee->id)
    //             ->pluck('id')
    //             ->toArray();
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | MANAGER
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($employee->designation === 'Manager') {

    //         return Employee::where('manager_id', $employee->id)
    //             ->orWhere('id', $employee->id)
    //             ->pluck('id')
    //             ->toArray();
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | TEAM LEADER
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($employee->designation === 'Team Leader') {

    //         return Employee::where('superviser_id', $employee->id)
    //             ->orWhere('id', $employee->id)
    //             ->pluck('id')
    //             ->toArray();
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | CALLER
    //     |--------------------------------------------------------------------------
    //     */

    //     return [$employee->id];
    // }

    /**
     * Employee ids whose leads and assignments the user may see: themselves
     * and their whole branch, exited levels included (see
     * HierarchyHelper::visibleSubordinateIds()). A caller reporting
     * straight to a Manager is part of that branch, as it always was here.
     *
     * @return array<int, int>
     */
    public static function visibleEmployeeIds(User $user): array
    {
        if ($user->hasRole('Admin')) {
            return Employee::pluck('id')->toArray();
        }

        $employee = $user->employee;

        if (! $employee || Employee::designationRank($employee->designation) === 0) {
            return [];
        }

        return HierarchyHelper::visibleSubordinateIds($employee)->all();
    }

    /**
     * @return array<int, int>
     */
    public static function subordinateEmployeeIds(Employee $employee): array
    {
        return HierarchyHelper::visibleSubordinateIds($employee)->all();
    }
}
