<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Support\HierarchyHelper;
use App\Support\ReportingTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly-target gate for the Daily Commitment module.
 *
 * A monthly target is fixed by hand, one row per employee per calendar
 * month (monthly_commitment_targets). From the 1st of every month those
 * rows do not exist yet, so the module raises a blocking prompt:
 *
 *  - Whoever is answerable for a target is told to fix it: the nearest
 *    boss on the rolls whose seat sets it (OWNER_SEATS). A caller's belongs
 *    to their Manager; a Team Leader's and a Manager's to their Cluster
 *    Manager or Business Head. When a level is skipped or has left, the
 *    duty passes to the next setter up. The Admin line also answers for
 *    every Manager and Team Leader, as it always has, but nobody reports
 *    directly to the Admin: a caller with no setter above them has a
 *    reporting line to fix and is nobody's duty until it is.
 *  - Everyone waiting on a target of their own is told who to chase.
 *
 * Until the current month's rows exist the rest of the panel is closed
 * (see App\Http\Middleware\EnsureMonthlyTargetIsSet).
 *
 * This is entirely the Daily Commitment module's own target. It never
 * reads or writes employees.category / employee_targets, and the LMS
 * achievement/incentive engine is not involved.
 */
class MonthlyTargetGate
{
    public const REASON_SET_TARGETS = 'set_targets';

    public const REASON_AWAITING_TARGET = 'awaiting_target';

    /**
     * Designations that must carry a monthly commitment target.
     *
     * @var array<int, int>
     */
    public const REQUIRES_TARGET = [
        Employee::DESIGNATION_MANAGER,
        Employee::DESIGNATION_TEAM_LEADER,
        Employee::DESIGNATION_CALLER,
    ];

    /**
     * What the Admin line is answerable for — Managers and Team Leaders.
     * Callers belong to their own Manager.
     *
     * @var array<int, int>
     */
    public const MANAGEMENT_DESIGNATIONS = [
        Employee::DESIGNATION_MANAGER,
        Employee::DESIGNATION_TEAM_LEADER,
    ];

    /**
     * Seats that set targets inside their own branch.
     *
     * @var array<int, int>
     */
    public const SETTER_SEATS = [
        Employee::DESIGNATION_MANAGER,
        Employee::DESIGNATION_CLUSTER,
        Employee::DESIGNATION_BUSINESS_HEAD,
    ];

    /**
     * Which seats may own each designation's target. The owner is the
     * nearest boss on the rolls holding one of them, so a skipped or exited
     * level passes the duty up instead of leaving the target with nobody.
     *
     * @var array<int, array<int, int>>
     */
    public const OWNER_SEATS = [
        Employee::DESIGNATION_CALLER => [
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_BUSINESS_HEAD,
        ],
        Employee::DESIGNATION_TEAM_LEADER => [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_BUSINESS_HEAD,
        ],
        Employee::DESIGNATION_MANAGER => [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_BUSINESS_HEAD,
        ],
    ];

    /**
     * Per-request memo — the middleware and the prompt component both ask
     * the same question on every panel request.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $statuses = [];

    /**
     * @var array<int, Collection<int, int>>
     */
    private array $assignable = [];

    /**
     * Per-request memo of the employees whose target is skipped this
     * month because an inactivity ticket is open for them.
     *
     * @var array<string, Collection<int, int>>
     */
    private array $skipped = [];

    /** The month the gate is currently policing: always the calendar month in progress. */
    public function month(): Carbon
    {
        return today()->startOfMonth();
    }

    /*
    |--------------------------------------------------------------------------
    | Who sets whose target
    |--------------------------------------------------------------------------
    */

    /**
     * Employees whose target this user MUST fix for the month. An empty
     * collection means the user has no target-setting duty at all.
     *
     * @return Collection<int, Employee>
     */
    public function responsibleFor(User $user): Collection
    {
        // Every Manager and Team Leader, as always — and never a caller.
        // Nobody reports directly to the Admin: a caller with no setter on
        // the rolls above them has a broken reporting line to fix, which
        // must not lock the Admin out of the panel.
        if ($this->isAdminLine($user)) {
            return $this->activeEmployees()
                ->whereIn('designation', self::MANAGEMENT_DESIGNATIONS)
                ->orderBy('designation')
                ->orderBy('emp_name')
                ->get();
        }

        $employee = $user->employee;

        if (! $employee || ! in_array($employee->designation, self::SETTER_SEATS, true)) {
            return collect();
        }

        $tree = ReportingTree::load();

        // A Cluster Manager or Business Head stands in for the Admin line
        // inside their own branch, so a cluster is never stuck waiting on
        // Admin; a Manager only ever owns callers (see OWNER_SEATS).
        return $this->activeEmployees()
            ->whereIn('id', $tree->descendantIds($employee->id))
            ->whereIn('designation', self::REQUIRES_TARGET)
            ->orderBy('designation')
            ->orderBy('emp_name')
            ->get()
            ->filter(fn (Employee $member): bool => $this->ownerIdIn($tree, $member) === $employee->id)
            ->values();
    }

    /**
     * Employees this user MAY set a target for. Wider than the duty:
     * Admin can correct anybody's target, a Cluster Manager or Business
     * Head anybody in their branch, while a Manager is still limited to
     * their callers.
     *
     * @return Collection<int, int>
     */
    public function assignableEmployeeIds(User $user): Collection
    {
        // Asked repeatedly per request — the resource's canAccess() alone
        // runs once per navigation build and once per page render.
        return $this->assignable[$user->getKey()] ??= $this->resolveAssignableEmployeeIds($user);
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveAssignableEmployeeIds(User $user): Collection
    {
        // The Admin line may set or correct anybody's target, at any
        // level and whether or not the module is currently waiting on it
        // — an Admin overruling a number is the whole point of the seat.
        if ($this->isAdminLine($user)) {
            return Employee::query()->pluck('id');
        }

        $employee = $user->employee;

        if (! $employee || ! in_array($employee->designation, self::SETTER_SEATS, true)) {
            return collect();
        }

        // The whole branch, exited levels included: when a Team Leader or
        // Manager leaves, the targets under them pass to the next setter up.
        return $this->activeEmployees()
            ->whereIn('id', HierarchyHelper::visibleSubordinateIds($employee))
            ->whereIn('designation', $employee->designation === Employee::DESIGNATION_MANAGER
                ? [Employee::DESIGNATION_CALLER]
                : self::REQUIRES_TARGET)
            ->pluck('id');
    }

    /**
     * Whether this user is a target setter at all — the Admin line, a
     * Business Head, a Cluster Manager or a Manager. Deliberately based on
     * the seat rather than on who currently happens to be under them, so a
     * Manager whose team is momentarily empty still reaches the Monthly
     * Target screen.
     */
    public function isTargetSetter(User $user): bool
    {
        if ($this->isAdminLine($user)) {
            return true;
        }

        $employee = $user->employee;

        return $employee !== null && in_array($employee->designation, self::SETTER_SEATS, true);
    }

    public function canSetTargetFor(User $user, int $employeeId): bool
    {
        return $this->assignableEmployeeIds($user)->contains($employeeId);
    }

    /*
    |--------------------------------------------------------------------------
    | What is still missing
    |--------------------------------------------------------------------------
    */

    /**
     * The user's own team members with no target row for the month.
     *
     * @return Collection<int, Employee>
     */
    public function missingTargets(User $user, ?Carbon $month = null): Collection
    {
        $responsible = $this->responsibleFor($user);

        if ($responsible->isEmpty()) {
            return $responsible;
        }

        $month ??= $this->month();

        $set = $this->employeeIdsWithTarget($responsible->pluck('id'), $month);
        $skipped = $this->skippedEmployeeIds($month);

        return $responsible
            ->reject(fn (Employee $employee): bool => $set->contains($employee->id)
                || $skipped->contains($employee->id))
            ->values();
    }

    /**
     * Employees whose monthly target is skipped because somebody has
     * raised an inactivity ticket for them. A ticket counts from the
     * moment it is raised — the team is not made to wait for the Admin to
     * review it, or one person who has stopped turning up would hold
     * everybody else out of the panel.
     *
     * @return Collection<int, int>
     */
    public function skippedEmployeeIds(?Carbon $month = null): Collection
    {
        $month ??= $this->month();
        $key = $month->toDateString();

        return $this->skipped[$key] ??= EmployeeInactivityRequest::query()
            ->forMonth($month)
            ->skipping()
            ->pluck('employee_id')
            ->unique()
            ->values();
    }

    /** Is this employee's target already skipped for the month? */
    public function isSkipped(int $employeeId, ?Carbon $month = null): bool
    {
        return $this->skippedEmployeeIds($month)->contains($employeeId);
    }

    /** Does this user's own designation need a target fixed for them? */
    public function requiresOwnTarget(User $user, ?Carbon $month = null): bool
    {
        // The Admin line sets targets rather than carrying one.
        if ($this->isAdminLine($user)) {
            return false;
        }

        $employee = $user->employee;

        return $employee !== null
            && in_array($employee->designation, self::REQUIRES_TARGET, true)
            && $employee->exit_status !== 'yes'
            && ! $this->isSkipped($employee->id, $month);
    }

    public function hasOwnTarget(User $user, ?Carbon $month = null): bool
    {
        $employee = $user->employee;

        return $employee !== null
            && $this->employeeIdsWithTarget(collect([$employee->id]), $month ?? $this->month())->isNotEmpty();
    }

    /**
     * Who a blocked employee has to chase: the nearest boss on the rolls
     * whose seat sets their target (see OWNER_SEATS). Null means nobody in
     * the tree does — for a Team Leader or Manager that is the Admin line;
     * for a caller it means their reporting line needs fixing.
     */
    public function targetSetterFor(Employee $employee): ?Employee
    {
        $ownerId = $this->ownerIdIn(ReportingTree::load(), $employee);

        return $ownerId !== null ? Employee::find($ownerId) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | The gate itself
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{blocked: bool, reason: ?string, month: Carbon, missing: Collection<int, Employee>, setter: ?Employee}
     */
    public function status(User $user, ?Carbon $month = null): array
    {
        $month ??= $this->month();
        $key = $user->getKey().'|'.$month->toDateString();

        if (isset($this->statuses[$key])) {
            return $this->statuses[$key];
        }

        $missing = $this->missingTargets($user, $month);

        // The duty comes first: it is the only thing the user can act on.
        if ($missing->isNotEmpty()) {
            return $this->statuses[$key] = [
                'blocked' => true,
                'reason' => self::REASON_SET_TARGETS,
                'month' => $month,
                'missing' => $missing,
                'setter' => null,
            ];
        }

        if ($this->requiresOwnTarget($user, $month) && ! $this->hasOwnTarget($user, $month)) {
            return $this->statuses[$key] = [
                'blocked' => true,
                'reason' => self::REASON_AWAITING_TARGET,
                'month' => $month,
                'missing' => collect(),
                'setter' => $this->targetSetterFor($user->employee),
            ];
        }

        return $this->statuses[$key] = [
            'blocked' => false,
            'reason' => null,
            'month' => $month,
            'missing' => collect(),
            'setter' => null,
        ];
    }

    public function isBlocked(User $user): bool
    {
        return $this->status($user)['blocked'];
    }

    /** Drop the per-request memo after targets have just been written. */
    public function forget(): void
    {
        $this->statuses = [];
        $this->assignable = [];
        $this->skipped = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The Admin — the only seat that reaches the whole company. The
     * Business Head role opens screens but never widens data: a Business
     * Head answers for their own branch through their employee record, and
     * that role is kept in step with the designation (HierarchyRoleService).
     */
    private function isAdminLine(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /** The id of the boss who owns $employee's target, or null for the Admin line. */
    private function ownerIdIn(ReportingTree $tree, Employee $employee): ?int
    {
        $seats = self::OWNER_SEATS[$employee->designation] ?? null;

        return $seats === null
            ? null
            : $tree->nearestAncestorId($employee->id, $seats, activeOnly: true);
    }

    /**
     * Employees who can actually be blocked: still on the rolls and able
     * to sign in. A row with no login can never see the prompt, so
     * demanding a target for it would deadlock whoever owns them.
     */
    private function activeEmployees(): Builder
    {
        return Employee::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('exit_status', '!=', 'yes')
                ->orWhereNull('exit_status'))
            ->whereHas('user');
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     * @return Collection<int, int>
     */
    private function employeeIdsWithTarget(Collection $employeeIds, Carbon $month): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return MonthlyCommitmentTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->forMonth($month)
            ->pluck('employee_id');
    }
}
