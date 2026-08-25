<?php

namespace App\Support;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HierarchyHelper
{
    /**
     * Get all visible employee IDs for the logged-in user.
     */
    public static function visibleEmployeeIds(User $user): Collection
    {
        /*
        |--------------------------------------------------------------------------
        | ADMIN
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('Admin')) {
            return Employee::query()
                ->where(function ($query) {
                    $query->where('designation', Employee::DESIGNATION_CALLER)
                        ->orWhere(function ($query) {
                            $query->whereIn('designation', [
                                Employee::DESIGNATION_CLUSTER,
                                Employee::DESIGNATION_MANAGER,
                                Employee::DESIGNATION_TEAM_LEADER,
                            ])
                                ->where('exit_status', '!=', 'yes');
                        });
                })
                ->pluck('id');
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

            return self::ids('cluster_id', $employee->id);
        }

        /*
        |--------------------------------------------------------------------------
        | MANAGER
        |--------------------------------------------------------------------------
        */

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            return self::ids('manager_id', $employee->id);
        }

        /*
        |--------------------------------------------------------------------------
        | TEAM LEADER
        |--------------------------------------------------------------------------
        */

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

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
            'caller' => $employee,
            'team_leader' => $employee->supervisor,
            'manager' => $employee->manager,
            'cluster' => $employee->cluster,
        ];
    }

    /**
     * Employee IDs the user is allowed to look up in the Reporting Hierarchy
     * page: their own downward team (self + subordinates) plus their upward
     * chain of superiors (team leader, manager, cluster manager). Admin
     * sees everyone. Nobody sees another team's hierarchy.
     */
    public static function ownHierarchyIds(User $user): Collection
    {
        if ($user->hasRole('Admin')) {
            return Employee::query()->pluck('id');
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        $downward = self::subordinateIds($employee);

        $upward = collect([
            $employee->superviser_id,
            $employee->manager_id,
            $employee->cluster_id,
        ])->filter();

        return $downward->merge($upward)->unique()->values();
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
                ->where('designation', Employee::DESIGNATION_CLUSTER)
                ->where('exit_status', '!=', 'yes');
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
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->where('exit_status', '!=', 'yes');
        }

        // Manager sees Team Leaders
        if ($employee->designation === Employee::DESIGNATION_MANAGER) {
            return Employee::query()
                ->where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->where('exit_status', '!=', 'yes');
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
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->where('exit_status', '!=', 'yes');
        }

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            return Employee::query()
                ->where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->where('exit_status', '!=', 'yes');
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
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            return Employee::whereIn('superviser_id', $teamLeaderIds)
                ->where('designation', Employee::DESIGNATION_CALLER)
                ->pluck('id');
        }

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            $managerIds = Employee::where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->where('exit_status', '!=', 'yes')
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
                'url' => TeamResource::getUrl('view-team', [
                    'record' => $employee->cluster,
                ]),
            ];
        }

        if ($employee->manager) {
            $items[] = [
                'label' => $employee->manager->emp_name,
                'url' => TeamResource::getUrl('view-team', [
                    'record' => $employee->manager,
                ]),
            ];
        }

        if ($employee->superviser) {
            $items[] = [
                'label' => $employee->superviser->emp_name,
                'url' => TeamResource::getUrl('view-team', [
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
                        ->where('exit_status', '!=', 'yes')
                        ->pluck('id')
                )
                ->push($employee->id),

            Employee::DESIGNATION_CLUSTER => self::callerIds($employee)
                ->merge(
                    Employee::where('cluster_id', $employee->id)
                        ->where('designation', Employee::DESIGNATION_MANAGER)
                        ->where('exit_status', '!=', 'yes')
                        ->pluck('id')
                )
                ->merge(
                    Employee::whereIn(
                        'manager_id',
                        Employee::where('cluster_id', $employee->id)
                            ->where('designation', Employee::DESIGNATION_MANAGER)
                            ->where('exit_status', '!=', 'yes')
                            ->pluck('id')
                    )
                        ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                        ->where('exit_status', '!=', 'yes')
                        ->pluck('id')
                )
                ->push($employee->id),

            default => collect([$employee->id]),
        };
    }

    /**
     * Get employee IDs visible in Login & Screen Time module.
     *
     * Rules:
     *
     * Admin
     *     → All employees
     *
     * Cluster Manager
     *     → Managers + Team Leaders + Callers
     *
     * Manager
     *     → Team Leaders + Callers
     *
     * Team Leader
     *     → Callers
     *
     * Caller
     *     → Self
     */
    public static function loginVisibleEmployeeIds(User $user): Collection
    {
        /*
        |--------------------------------------------------------------------------
        | ADMIN
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('Admin')) {
            return Employee::query()
                ->where(function ($query) {
                    $query->where('designation', Employee::DESIGNATION_CALLER)
                        ->orWhere(function ($query) {
                            $query->whereIn('designation', [
                                Employee::DESIGNATION_CLUSTER,
                                Employee::DESIGNATION_MANAGER,
                                Employee::DESIGNATION_TEAM_LEADER,
                            ])
                                ->where('exit_status', '!=', 'yes');
                        });
                })
                ->pluck('id')
                ->unique()
                ->values();
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        /*
        |--------------------------------------------------------------------------
        | CLUSTER MANAGER
        |--------------------------------------------------------------------------
        |
        | Cluster Manager sees:
        | Managers
        | Team Leaders
        | Callers
        |
        | Does NOT include the Cluster Manager itself.
        */

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            $managerIds = Employee::query()
                ->where('cluster_id', $employee->id)
                ->where(
                    'designation',
                    Employee::DESIGNATION_MANAGER
                )
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $teamLeaderIds = Employee::query()
                ->whereIn('manager_id', $managerIds)
                ->where(
                    'designation',
                    Employee::DESIGNATION_TEAM_LEADER
                )
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $callerIds = Employee::query()
                ->whereIn('superviser_id', $teamLeaderIds)
                ->where(
                    'designation',
                    Employee::DESIGNATION_CALLER
                )
                ->pluck('id');

            return $managerIds
                ->merge($teamLeaderIds)
                ->merge($callerIds)
                ->unique()
                ->values();
        }

        /*
        |--------------------------------------------------------------------------
        | MANAGER
        |--------------------------------------------------------------------------
        |
        | Manager sees:
        | Team Leaders
        | Callers
        |
        | Does NOT include Manager itself.
        */

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            $teamLeaderIds = Employee::query()
                ->where('manager_id', $employee->id)
                ->where(
                    'designation',
                    Employee::DESIGNATION_TEAM_LEADER
                )
                ->where('exit_status', '!=', 'yes')
                ->pluck('id');

            $callerIds = Employee::query()
                ->whereIn('superviser_id', $teamLeaderIds)
                ->where(
                    'designation',
                    Employee::DESIGNATION_CALLER
                )
                ->pluck('id');

            return $teamLeaderIds
                ->merge($callerIds)
                ->unique()
                ->values();
        }

        /*
        |--------------------------------------------------------------------------
        | TEAM LEADER
        |--------------------------------------------------------------------------
        |
        | Team Leader sees:
        | Callers only.
        */

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

            return Employee::query()
                ->where(
                    'superviser_id',
                    $employee->id
                )
                ->where(
                    'designation',
                    Employee::DESIGNATION_CALLER
                )
                ->pluck('id')
                ->unique()
                ->values();
        }

        /*
        |--------------------------------------------------------------------------
        | CALLER
        |--------------------------------------------------------------------------
        |
        | Caller sees only himself.
        */

        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return collect([
                $employee->id,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | FALLBACK
        |--------------------------------------------------------------------------
        */

        return collect();
    }
}
