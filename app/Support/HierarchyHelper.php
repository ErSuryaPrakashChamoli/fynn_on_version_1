<?php

namespace App\Support;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who reports to whom, for the whole app.
 *
 * Every walk here is answered by ReportingTree, which links each employee
 * to their direct boss — the nearest filled reporting column. A skipped
 * level (a Team Leader with no Manager, a caller with no Team Leader) is a
 * null column, so that branch hangs straight off the next boss up and is
 * counted and seen there.
 */
class HierarchyHelper
{
    /**
     * Get all visible employee IDs for the logged-in user.
     *
     * Admin sees every caller plus every Team Leader, Manager, Cluster
     * Manager and Business Head still on the rolls. Anybody else sees
     * themselves and their whole branch, exited levels included.
     *
     * @return Collection<int, int>
     */
    public static function visibleEmployeeIds(User $user): Collection
    {
        if ($user->hasRole('Admin')) {
            return self::adminVisibleIds(ReportingTree::load());
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return self::visibleSubordinateIds($employee);
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
        return [
            'caller' => $employee,
            'team_leader' => $employee->superviser,
            'manager' => $employee->manager,
            'cluster' => $employee->cluster,
            'business_head' => $employee->businessHead,
        ];
    }

    /**
     * Employee IDs the user is allowed to look up in the Reporting Hierarchy
     * page: their own downward team (self + subordinates) plus every boss
     * above them, whichever levels exist. Admin sees everyone. Nobody sees
     * another team's hierarchy.
     *
     * @return Collection<int, int>
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

        $tree = ReportingTree::load();

        return collect($tree->descendantIds($employee->id, skipExitedLevels: true))
            ->push($employee->id)
            ->merge($tree->ancestorIds($employee->id))
            ->unique()
            ->values();
    }

    /**
     * The eligible-backup pool for Team Continuity: every employee within
     * the SAME top-level branch (cluster) as $employee — i.e. the whole
     * subordinate tree of $employee's own Cluster Manager (or of the
     * highest boss below Business Head level when the Cluster Manager
     * level is skipped). This deliberately includes sibling Managers/Team
     * Leaders under the same cluster (a legitimate backup per spec section
     * 12's own example — "eligible Manager", "another eligible employee
     * within the permitted hierarchy"), while excluding anyone in a
     * different Cluster. The climb never goes past a Cluster Manager to
     * their Business Head, which would widen the pool to every cluster.
     *
     * @return Collection<int, int>
     */
    public static function employeeHierarchyIds(Employee $employee): Collection
    {
        $tree = ReportingTree::load();
        $rootId = $tree->branchRootId($employee->id);

        return collect($tree->descendantIds($rootId, skipExitedLevels: true))
            ->push($rootId);
    }

    public static function directReportees(User $user): Builder
    {
        // Admin starts at the top of every branch: each Business Head, and
        // each Cluster Manager with no Business Head on the rolls above.
        if ($user->hasRole('Admin')) {
            $tree = ReportingTree::load();

            $topIds = array_filter(
                $tree->employeeIds(),
                fn (int $id): bool => in_array($tree->designation($id), [
                    Employee::DESIGNATION_BUSINESS_HEAD,
                    Employee::DESIGNATION_CLUSTER,
                ], true)
                    && ! $tree->isExited($id)
                    && ($tree->bossId($id) === null || $tree->isExited($tree->bossId($id))),
            );

            return Employee::query()->whereIn('id', array_values($topIds));
        }

        $employee = $user->employee;

        if (! $employee) {
            return Employee::query()->whereRaw('1 = 0');
        }

        return self::children($employee);
    }

    /**
     * The people reporting straight to $employee, at whatever level they
     * sit — a Cluster Manager's direct reports can be Managers and also
     * Team Leaders who skip the Manager level. An exited Team Leader,
     * Manager or Cluster Manager is left out; callers never are.
     */
    public static function children(Employee $employee): Builder
    {
        return Employee::query()->whereIn(
            'id',
            ReportingTree::load()->childIds($employee->id, skipExitedLevels: true)
        );
    }

    /**
     * Every caller $employee's figures count: their whole branch, stopping
     * at an exited Team Leader, Manager or Cluster Manager.
     *
     * @return Collection<int, int>
     */
    public static function callerIds(Employee $employee): Collection
    {
        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return collect([$employee->id]);
        }

        $tree = ReportingTree::load();

        return collect($tree->descendantIds($employee->id, skipExitedLevels: true))
            ->filter(fn (int $id): bool => $tree->designation($id) === Employee::DESIGNATION_CALLER)
            ->values();
    }

    /**
     * For every Team Leader $employee's target counts — themselves too, if
     * they are one — how many callers report straight to them. Exited
     * callers are included: the understaffed-team top-up has always counted
     * them.
     *
     * @return array<int, int> team leader id => caller count
     */
    public static function teamLeaderCallerCounts(Employee $employee): array
    {
        $tree = ReportingTree::load();
        $counts = [];

        foreach ([$employee->id, ...$tree->descendantIds($employee->id, skipExitedLevels: true)] as $id) {
            if ($tree->designation($id) !== Employee::DESIGNATION_TEAM_LEADER) {
                continue;
            }

            $counts[$id] = count(array_filter(
                $tree->childIds($id),
                fn (int $childId): bool => $tree->designation($childId) === Employee::DESIGNATION_CALLER,
            ));
        }

        return $counts;
    }

    /**
     * Every boss above $employee, top first, then $employee themselves.
     *
     * @return array<int, array{label: string, url: ?string}>
     */
    public static function breadcrumb(Employee $employee): array
    {
        $tree = ReportingTree::load();
        $items = [];

        foreach (array_reverse($tree->ancestorIds($employee->id)) as $ancestorId) {
            $items[] = [
                'label' => (string) $tree->name($ancestorId),
                'url' => TeamResource::getUrl('view-team', [
                    'record' => $ancestorId,
                ]),
            ];
        }

        $items[] = [
            'label' => $employee->emp_name,
            'url' => null,
        ];

        return $items;
    }

    /**
     * $employee plus everyone their target, incentive and target-setting
     * maths counts. Stops at an exited Team Leader / Manager / Cluster
     * Manager — see visibleSubordinateIds() for why the two walks differ.
     *
     * @return Collection<int, int>
     */
    public static function subordinateIds(Employee $employee): Collection
    {
        return collect(ReportingTree::load()->descendantIds($employee->id, skipExitedLevels: true))
            ->push($employee->id);
    }

    /**
     * Every employee id whose records $employee may SEE.
     *
     * The same tree as subordinateIds(), except it does not stop at an
     * exited Team Leader / Manager. Marking somebody inactive only sets
     * employees.exit_status — it never reassigns their customers, leads or
     * follow-ups — so a tree that skipped an exited level made every case
     * under that level (including those of the callers still working under
     * them) invisible to everyone but the Admin. The exited employee's own
     * assigned cases stay visible here too, since somebody still on the
     * rolls has to be able to pick them up.
     *
     * Use this for visibility only. Target, incentive and target-setting
     * maths deliberately stop at an exited level and must keep calling
     * subordinateIds()/callerIds() — see AchievementCalculatorService and
     * MonthlyTargetGate.
     *
     * @return Collection<int, int>
     */
    public static function visibleSubordinateIds(Employee $employee): Collection
    {
        return collect(ReportingTree::load()->descendantIds($employee->id))
            ->push($employee->id)
            ->unique()
            ->values();
    }

    /**
     * The employee $employee reports to directly, or null at the top.
     */
    public static function directBossId(Employee $employee): ?int
    {
        return ReportingTree::load()->bossId($employee->id);
    }

    /**
     * Every boss above $employee, nearest first.
     *
     * @return Collection<int, int>
     */
    public static function ancestorIds(Employee $employee): Collection
    {
        return collect(ReportingTree::load()->ancestorIds($employee->id));
    }

    /**
     * The nearest boss above $employee holding one of $designations.
     *
     * @param  array<int, int>  $designations
     */
    public static function nearestAncestor(Employee $employee, array $designations, bool $activeOnly = false): ?Employee
    {
        $ancestorId = ReportingTree::load()->nearestAncestorId($employee->id, $designations, $activeOnly);

        return $ancestorId !== null ? Employee::find($ancestorId) : null;
    }

    /**
     * Get employee IDs visible in Login & Screen Time module.
     *
     * Rules:
     *
     * Admin
     *     → Every caller, and every level above still on the rolls
     *
     * Team Leader, Manager, Cluster Manager, Business Head
     *     → Their counted branch, WITHOUT themselves
     *
     * Caller
     *     → Self
     *
     * @return Collection<int, int>
     */
    public static function loginVisibleEmployeeIds(User $user): Collection
    {
        if ($user->hasRole('Admin')) {
            return self::adminVisibleIds(ReportingTree::load());
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return collect([$employee->id]);
        }

        return collect(ReportingTree::load()->descendantIds($employee->id, skipExitedLevels: true));
    }

    /**
     * Every caller, plus every Team Leader, Manager, Cluster Manager and
     * Business Head still on the rolls.
     *
     * @return Collection<int, int>
     */
    private static function adminVisibleIds(ReportingTree $tree): Collection
    {
        return collect($tree->employeeIds())
            ->filter(fn (int $id): bool => $tree->designation($id) === Employee::DESIGNATION_CALLER
                || (Employee::designationRank($tree->designation($id)) > 0 && ! $tree->isExited($id)))
            ->values();
    }
}
