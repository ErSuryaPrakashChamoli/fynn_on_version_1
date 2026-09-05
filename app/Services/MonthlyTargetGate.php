<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Support\HierarchyHelper;
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
 *  - Whoever is answerable for a target is told to fix it. A Manager
 *    fixes their own callers; the Admin line (Admin / Business Head, and
 *    a Cluster Manager inside their own branch) fixes Managers and Team
 *    Leaders.
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
        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return $this->activeEmployees()
                ->whereIn('designation', self::MANAGEMENT_DESIGNATIONS)
                ->orderBy('designation')
                ->orderBy('emp_name')
                ->get();
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return match ($employee->designation) {
            // A Cluster Manager stands in for the Admin line inside their
            // own branch, so a cluster is never stuck waiting on Admin.
            Employee::DESIGNATION_CLUSTER => $this->activeEmployees()
                ->whereIn('id', HierarchyHelper::subordinateIds($employee))
                ->whereIn('designation', self::MANAGEMENT_DESIGNATIONS)
                ->orderBy('designation')
                ->orderBy('emp_name')
                ->get(),

            Employee::DESIGNATION_MANAGER => $this->activeEmployees()
                ->whereIn('id', HierarchyHelper::callerIds($employee))
                ->orderBy('emp_name')
                ->get(),

            default => collect(),
        };
    }

    /**
     * Employees this user MAY set a target for. Wider than the duty:
     * Admin can correct anybody's target, a Cluster Manager anybody in
     * their branch, while a Manager is still limited to their callers.
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
        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return $this->activeEmployees()
                ->whereIn('designation', self::REQUIRES_TARGET)
                ->pluck('id');
        }

        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return match ($employee->designation) {
            Employee::DESIGNATION_CLUSTER => $this->activeEmployees()
                ->whereIn('id', HierarchyHelper::subordinateIds($employee))
                ->whereIn('designation', self::REQUIRES_TARGET)
                ->pluck('id'),

            Employee::DESIGNATION_MANAGER => $this->activeEmployees()
                ->whereIn('id', HierarchyHelper::callerIds($employee))
                ->pluck('id'),

            default => collect(),
        };
    }

    /**
     * Whether this user is a target setter at all — the Admin line, a
     * Cluster Manager or a Manager. Deliberately based on the seat rather
     * than on who currently happens to be under them, so a Manager whose
     * team is momentarily empty still reaches the Monthly Target screen.
     */
    public function isTargetSetter(User $user): bool
    {
        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return true;
        }

        $employee = $user->employee;

        return $employee !== null && in_array($employee->designation, [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_MANAGER,
        ], true);
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

        $set = $this->employeeIdsWithTarget($responsible->pluck('id'), $month ?? $this->month());

        return $responsible->reject(fn (Employee $employee): bool => $set->contains($employee->id))->values();
    }

    /** Does this user's own designation need a target fixed for them? */
    public function requiresOwnTarget(User $user): bool
    {
        // The Admin line sets targets rather than carrying one.
        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return false;
        }

        $employee = $user->employee;

        return $employee !== null
            && in_array($employee->designation, self::REQUIRES_TARGET, true)
            && $employee->exit_status !== 'yes';
    }

    public function hasOwnTarget(User $user, ?Carbon $month = null): bool
    {
        $employee = $user->employee;

        return $employee !== null
            && $this->employeeIdsWithTarget(collect([$employee->id]), $month ?? $this->month())->isNotEmpty();
    }

    /**
     * Who a blocked employee has to chase. A caller's target belongs to
     * their Manager; a Team Leader's and a Manager's belong to the Admin
     * line, which is why this returns null for them.
     */
    public function targetSetterFor(Employee $employee): ?Employee
    {
        if ($employee->designation !== Employee::DESIGNATION_CALLER) {
            return null;
        }

        return $employee->manager ?? $employee->superviser?->manager;
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

        if ($this->requiresOwnTarget($user) && ! $this->hasOwnTarget($user, $month)) {
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
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

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
