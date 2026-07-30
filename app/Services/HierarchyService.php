<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;

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


    public static function visibleEmployeeIds(User $user): array
    {
        if ($user->hasRole('Admin')) {
            return Employee::pluck('id')->toArray();
        }

        $employee = $user->employee;

        if (! $employee) {
            return [];
        }

        switch ($employee->designation) {

            /*
        |--------------------------------------------------------------------------
        | CLUSTER MANAGER
        |--------------------------------------------------------------------------
        */
            case Employee::DESIGNATION_CLUSTER:

                $managerIds = Employee::where('cluster_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_MANAGER)
                    ->pluck('id')
                    ->toArray();

                $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')
                    ->toArray();

                $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                return array_unique(array_merge(
                    [$employee->id],
                    $managerIds,
                    $teamLeaderIds,
                    $callerIds
                ));

                /*
        |--------------------------------------------------------------------------
        | MANAGER
        |--------------------------------------------------------------------------
        */
            case Employee::DESIGNATION_MANAGER:

                $teamLeaderIds = Employee::where('manager_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')
                    ->toArray();

                $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                $directCallers = Employee::where('manager_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                return array_unique(array_merge(
                    [$employee->id],
                    $teamLeaderIds,
                    $callerIds,
                    $directCallers
                ));

                /*
        |--------------------------------------------------------------------------
        | TEAM LEADER
        |--------------------------------------------------------------------------
        */
            case Employee::DESIGNATION_TEAM_LEADER:

                $callerIds = Employee::where('superviser_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                return array_unique(array_merge(
                    [$employee->id],
                    $callerIds
                ));

                /*
        |--------------------------------------------------------------------------
        | CALLER
        |--------------------------------------------------------------------------
        */
            case Employee::DESIGNATION_CALLER:

                return [$employee->id];

            default:

                return [];
        }
    }


    public static function subordinateEmployeeIds(Employee $employee): array
    {
        switch ($employee->designation) {

            case Employee::DESIGNATION_CALLER:
                return [$employee->id];

            case Employee::DESIGNATION_TEAM_LEADER:

                return Employee::where('superviser_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->push($employee->id)
                    ->toArray();

            case Employee::DESIGNATION_MANAGER:

                $teamLeaderIds = Employee::where('manager_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')
                    ->toArray();

                $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                return array_merge([$employee->id], $teamLeaderIds, $callerIds);

            case Employee::DESIGNATION_CLUSTER:

                $managerIds = Employee::where('cluster_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_MANAGER)
                    ->pluck('id')
                    ->toArray();

                $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')
                    ->toArray();

                $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')
                    ->toArray();

                return array_merge(
                    [$employee->id],
                    $managerIds,
                    $teamLeaderIds,
                    $callerIds
                );

            default:
                return [$employee->id];
        }
    }
}
