<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class HierarchyHelper
{
    /**
     * Get all visible employee IDs for the logged-in user.
     */
    // public static function visibleEmployeeIds(User $user): Collection
    // {
    //     // We will implement this in the next step.
    //     return collect();
    // }

    public static function visibleEmployeeIds(User $user): Collection
    {
        /*
    |--------------------------------------------------------------------------
    | ADMIN
    |--------------------------------------------------------------------------
    */

        if ($user->hasRole('Admin')) {
            return Employee::pluck('id');
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        /*
    |--------------------------------------------------------------------------
    | CLUSTER MANAGER
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            // return Employee::where('cluster_id', $employee->id)
            //     ->orWhere('id', $employee->id)
            //     ->pluck('id');

            return self::ids('cluster_id', $employee->id);
        }

        /*
    |--------------------------------------------------------------------------
    | MANAGER
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            // return Employee::where('manager_id', $employee->id)
            //     ->orWhere('id', $employee->id)
            //     ->pluck('id');
            return self::ids('manager_id', $employee->id);
        }

        /*
    |--------------------------------------------------------------------------
    | TEAM LEADER
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

            // return Employee::where('superviser_id', $employee->id)
            //     ->orWhere('id', $employee->id)
            //     ->pluck('id');
            return self::ids('superviser_id', $employee->id);
        }

        /*
    |--------------------------------------------------------------------------
    | CALLER
    |--------------------------------------------------------------------------
    */

        return collect([$employee->id]);
    }

    /**
     * Get visible employees query.
     */
    public static function visibleEmployees(User $user): Builder
    {
        // We will implement this in the next step.
        // return Employee::query();

        return Employee::query()
            ->whereIn(
                'id',
                self::visibleEmployeeIds($user)
            );
    }

    /**
     * Check whether a user can view an employee.
     */
    public static function canViewEmployee(User $viewer, Employee $employee): bool
    {
        // We will implement this in the next step.
        // return false;
        return self::visibleEmployeeIds($viewer)
            ->contains($employee->id);
    }

    /**
     * Check whether a user can manage an employee.
     */
    public static function canManageEmployee(User $viewer, Employee $employee): bool
    {
        // We will implement this in the next step.
        // return false;
        if ($viewer->hasRole('Admin')) {
            return true;
        }
        return self::canViewEmployee($viewer, $employee);
    }

    /**
     * Get the reporting chain of an employee.
     */
    public static function getReportingChain(Employee $employee): array
    {
        // We will implement this in the next step.
        // return [];
        return [
            'caller'       => $employee,
            'team_leader'  => $employee->supervisor,
            'manager'      => $employee->manager,
            'cluster'      => $employee->cluster,
        ];
    }

    private static function ids(string $column, int $id): Collection
    {
        return Employee::query()
            ->where(function ($query) use ($column, $id) {
                $query->where($column, $id)
                    ->orWhere('id', $id);
            })
            ->pluck('id')
            ->unique()
            ->values();
    }


    public static function directReportees(User $user): Builder
    {


        // Admin sees Cluster Managers
        if ($user->hasRole('Admin')) {
            return Employee::query()
                ->where('designation', Employee::DESIGNATION_CLUSTER);
        }

        $employee = $user->employee;
        // dd($user);
        // dd($employee->designation);


        if (! $employee) {
            return Employee::query()->whereRaw('1 = 0');
        }

        // Cluster Manager sees Managers
        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {
            return Employee::query()
                ->where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER);
        }

        // Manager sees Team Leaders
        if ($employee->designation === Employee::DESIGNATION_MANAGER) {
            return Employee::query()
                ->where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER);
        }

        // Team Leader sees Callers
        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {
            // dd("enter here");
            return Employee::query()
                ->where('superviser_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_CALLER);
        }

        // Caller sees nobody in Team module
        return Employee::query()->whereRaw('1 = 0');
    }



    public static function children(Employee $employee): Builder
    {


        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            return Employee::query()
                ->where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER);
        }

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            return Employee::query()
                ->where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER);
        }

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

            return Employee::query()
                ->where('superviser_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_CALLER);
        }

        return Employee::query()->whereRaw('1=0');
    }



    public static function callerIds(Employee $employee): Collection
    {
        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return collect([$employee->id]);
        }

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {
            return Employee::where('superviser_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_CALLER)
                ->pluck('id');
        }

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            $teamLeaderIds = Employee::where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->pluck('id');

            return Employee::whereIn('superviser_id', $teamLeaderIds)
                ->where('designation', Employee::DESIGNATION_CALLER)
                ->pluck('id');
        }

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            $managerIds = Employee::where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->pluck('id');

            $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->pluck('id');

            return Employee::whereIn('superviser_id', $teamLeaderIds)
                ->where('designation', Employee::DESIGNATION_CALLER)
                ->pluck('id');
        }

        return collect();
    }


    public static function breadcrumb(Employee $employee): array
    {
        $items = [];

        if ($employee->cluster) {
            $items[] = [
                'label' => $employee->cluster->emp_name,
                'url' => \App\Filament\Resources\Teams\TeamResource::getUrl('view-team', [
                    'record' => $employee->cluster,
                ]),
            ];
        }

        if ($employee->manager) {
            $items[] = [
                'label' => $employee->manager->emp_name,
                'url' => \App\Filament\Resources\Teams\TeamResource::getUrl('view-team', [
                    'record' => $employee->manager,
                ]),
            ];
        }

        if ($employee->superviser) {
            $items[] = [
                'label' => $employee->superviser->emp_name,
                'url' => \App\Filament\Resources\Teams\TeamResource::getUrl('view-team', [
                    'record' => $employee->superviser,
                ]),
            ];
        }

        $items[] = [
            'label' => $employee->emp_name,
            'url' => null,
        ];

        return $items;
    }


    public static function subordinateIds(Employee $employee): Collection
    {
        return match ($employee->designation) {

            Employee::DESIGNATION_CALLER => collect([$employee->id]),

            Employee::DESIGNATION_TEAM_LEADER => self::callerIds($employee)
                ->push($employee->id),

            Employee::DESIGNATION_MANAGER => self::callerIds($employee)
                ->merge(
                    Employee::where('manager_id', $employee->id)
                        ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                        ->pluck('id')
                )
                ->push($employee->id),

            Employee::DESIGNATION_CLUSTER => self::callerIds($employee)
                ->merge(
                    Employee::where('cluster_id', $employee->id)
                        ->where('designation', Employee::DESIGNATION_MANAGER)
                        ->pluck('id')
                )
                ->merge(
                    Employee::whereIn(
                        'manager_id',
                        Employee::where('cluster_id', $employee->id)
                            ->pluck('id')
                    )
                        ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                        ->pluck('id')
                )
                ->push($employee->id),

            default => collect([$employee->id]),
        };
    }

    private function getCallerTarget(Employee $employee): float
    {
        $today = Carbon::today();

        $currentMonth = $today->month;
        $currentYear  = $today->year;
        $monthEnd     = $today->copy()->endOfMonth();

        /*
    |--------------------------------------------------------------------------
    | Employee must have DOJ
    |--------------------------------------------------------------------------
    */

        if (empty($employee->doj)) {
            return 0;
        }

        $joiningDate = Carbon::parse($employee->doj);

        /*
    |--------------------------------------------------------------------------
    | EXIT EMPLOYEE
    |--------------------------------------------------------------------------
    |
    | If employee exited in current month,
    | calculate target based on actual working days.
    |
    */

        if (
            $employee->exit_status === 'yes' &&
            !empty($employee->exit_date)
        ) {

            $exitDate = Carbon::parse($employee->exit_date);

            if (
                $exitDate->month == $currentMonth &&
                $exitDate->year == $currentYear
            ) {

                if ($exitDate->lt($joiningDate)) {
                    return 0;
                }

                $workedDays = $joiningDate->diffInDays($exitDate) + 1;

                return $workedDays >= 10
                    ? 1500000
                    : 0;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | NEW JOINER
    |--------------------------------------------------------------------------
    |
    | Only DOJ determines whether employee is a new joiner.
    | Reporting date is ignored.
    |
    */

        if (
            $joiningDate->month == $currentMonth &&
            $joiningDate->year == $currentYear
        ) {

            $remainingDays = $joiningDate->diffInDays($monthEnd) + 1;

            return $remainingDays >= 10
                ? 1500000
                : 0;
        }

        /*
    |--------------------------------------------------------------------------
    | EXISTING EMPLOYEE
    |--------------------------------------------------------------------------
    |
    | Existing employees always carry full target,
    | even if reporting manager/TL changes.
    |
    */

        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
    }
}
