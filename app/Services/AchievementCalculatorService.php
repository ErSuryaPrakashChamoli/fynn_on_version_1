<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use Carbon\Carbon;
use App\Services\HierarchyService;
use App\Support\HierarchyHelper;
use Illuminate\Support\Facades\DB;

class AchievementCalculatorService
{


    public function getCountAchievement(Employee $employee): float
    {
        if ($employee->designation === Employee::DESIGNATION_ADMIN) {

            $customers = Customer::query();
        } else {

            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customers = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        $customers
            ->whereMonth('customers.created_at', now()->month)
            ->whereYear('customers.created_at', now()->year);

        $totals = $customers
            ->leftJoin('customer_settlements as cs', function ($join) {
                $join->on('cs.customer_id', '=', 'customers.id')
                    ->where('cs.version', 1);
            })
            ->selectRaw("
                SUM(CASE WHEN cs.mis_disbursal_amount IS NOT NULL THEN cs.mis_disbursal_amount ELSE customers.sanctioned_loan_amount END) as actual,
                SUM(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END) as cashback,
                SUM(CASE WHEN cs.mis_subvention IS NOT NULL THEN cs.mis_subvention ELSE customers.subvention END) as subvention,
                SUM(CASE WHEN cs.mis_docking IS NOT NULL THEN cs.mis_docking ELSE customers.docking END) as docking
            ")
            ->first();

        $achievement = (float) ($totals->actual ?? 0);
        $cashback = (float) ($totals->cashback ?? 0);
        $subvention = (float) ($totals->subvention ?? 0);
        $docking = (float) ($totals->docking ?? 0);

        return $achievement
            - ((($cashback + $subvention + $docking) / 2) * 100);
    }




    public function getTarget(Employee $employee): float
    {
        /*
    |--------------------------------------------------------------------------
    | Caller
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return $this->getCallerTarget($employee);
        }

        /*
    |--------------------------------------------------------------------------
    | Team Leader
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

            $callerIds = HierarchyHelper::callerIds($employee);

            $target = Employee::whereIn('id', $callerIds)
                ->get()
                ->sum(
                    fn(Employee $caller) =>
                    $this->getHierarchyCallerTarget($caller)
                );
            // ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

            if ($callerIds->count() < 3) {
                $target += 3000000;
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Manager
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_MANAGER) {

            $target = Employee::whereIn(
                'id',
                HierarchyHelper::callerIds($employee)
            )
                ->get()
                ->sum(fn(Employee $caller) => $this->getHierarchyCallerTarget($caller));
            // ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

            $teamLeaderIds = Employee::where('manager_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->pluck('id');

            foreach ($teamLeaderIds as $tlId) {

                $callerCount = Employee::where('superviser_id', $tlId)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->count();

                if ($callerCount < 3) {
                    $target += 3000000;
                }
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Cluster Manager
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

            $target = Employee::whereIn(
                'id',
                HierarchyHelper::callerIds($employee)
            )
                ->get()
                ->sum(
                    fn(Employee $caller) =>
                    $this->getHierarchyCallerTarget($caller)
                );
            // ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

            $managerIds = Employee::where('cluster_id', $employee->id)
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->pluck('id');

            $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                ->pluck('id');

            foreach ($teamLeaderIds as $tlId) {

                $callerCount = Employee::where('superviser_id', $tlId)
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->count();

                if ($callerCount < 3) {
                    $target += 3000000;
                }
            }

            return $target;
        }

        /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

        if ($employee->designation === Employee::DESIGNATION_ADMIN) {

            return Employee::where('designation', Employee::DESIGNATION_CALLER)
                ->get()
                ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));
        }

        return 0;
    }

    public function getPercentage(Employee $employee): float
    {
        $target = $this->getTarget($employee);

        if ($target <= 0) {
            return 0;
        }

        return round(
            ($this->getCountAchievement($employee) / $target) * 100,
            2
        );
    }


    public function getEligibleCallerCount(Employee $manager): int
    {
        /*
    |--------------------------------------------------------------------------
    | Get all callers under this Manager
    |--------------------------------------------------------------------------
    */

        $callerIds = HierarchyHelper::callerIds($manager);

        if ($callerIds->isEmpty()) {
            return 0;
        }

        $callers = Employee::whereIn('id', $callerIds)->get();

        /*
    |--------------------------------------------------------------------------
    | Eligible Caller
    |--------------------------------------------------------------------------
    |
    | A caller is eligible if his/her target for the
    | current month is greater than zero.
    |
    */

        return $callers
            ->filter(fn(Employee $caller) => $this->getCallerTarget($caller) > 0)
            ->count();
    }

    public function getPPPMultiplier(float $ppp): float
    {
        return match (true) {

            $ppp >= 3100000 => 0.00075,

            $ppp >= 2900000 => 0.00060,

            $ppp >= 2700000 => 0.00050,

            $ppp >= 2500000 => 0.00040,

            $ppp >= 2300000 => 0.00030,

            default => 0,
        };
    }

    public function getPerformance(?Employee $employee): array
    {
        /*
    |--------------------------------------------------------------------------
    | COMPANY VIEW
    |--------------------------------------------------------------------------
    |
    | $employee === null means this is the Admin dashboard/company view.
    |
    | When an Employee is passed, ALWAYS calculate for that employee,
    | even when the logged-in user is Admin.
    |
    */

        $isCompanyView = $employee === null;

        /*
    |--------------------------------------------------------------------------
    | Customer Scope
    |--------------------------------------------------------------------------
    */

        if ($isCompanyView) {

            // Admin dashboard: all company customers
            $customers = Customer::query();
        } else {

            // Employee/Team table: only this employee's hierarchy
            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customers = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        /*
    |--------------------------------------------------------------------------
    | Current Month
    |--------------------------------------------------------------------------
    */

        $customers
            ->whereMonth('customers.created_at', now()->month)
            ->whereYear('customers.created_at', now()->year);

        /*
    |--------------------------------------------------------------------------
    | Actual / Deductions
    |--------------------------------------------------------------------------
    */

        $totals = $customers
            ->leftJoin('customer_settlements as cs', function ($join) {
                $join->on('cs.customer_id', '=', 'customers.id')
                    ->where('cs.version', 1);
            })
            ->selectRaw("
            SUM(CASE WHEN cs.mis_disbursal_amount IS NOT NULL THEN cs.mis_disbursal_amount ELSE customers.sanctioned_loan_amount END) as actual,
            SUM(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END) as cashback,
            SUM(CASE WHEN cs.mis_subvention IS NOT NULL THEN cs.mis_subvention ELSE customers.subvention END) as subvention,
            SUM(CASE WHEN cs.mis_docking IS NOT NULL THEN cs.mis_docking ELSE customers.docking END) as docking
        ")
            ->first();

        $actual = (float) ($totals->actual ?? 0);

        $cashback = (float) ($totals->cashback ?? 0);

        $subvention = (float) ($totals->subvention ?? 0);

        $docking = (float) ($totals->docking ?? 0);

        /*
    |--------------------------------------------------------------------------
    | Count Achievement
    |--------------------------------------------------------------------------
    */

        $countAchievement = $actual
            - ((($cashback + $subvention + $docking) / 2) * 100);

        /*
    |--------------------------------------------------------------------------
    | Target
    |--------------------------------------------------------------------------
    */

        if ($isCompanyView) {

            // Admin dashboard: sum of all caller category targets
            $target = Employee::query()
                ->where(
                    'designation',
                    Employee::DESIGNATION_CALLER
                )
                ->get()
                ->sum(
                    fn(Employee $caller) =>
                    $this->getCallerTarget($caller)
                );
        } else {

            // Teams table / employee dashboard:
            // target belongs to the employee being evaluated
            $target = $this->getTarget($employee);
        }

        /*
    |--------------------------------------------------------------------------
    | Percentage
    |--------------------------------------------------------------------------
    */

        $percentage = $target > 0
            ? round(
                ($countAchievement / $target) * 100,
                2
            )
            : 0;

        /*
    |--------------------------------------------------------------------------
    | Result
    |--------------------------------------------------------------------------
    */

        return [
            'target_category'   => $employee?->category,
            'target'            => (float) $target,
            'actual'            => $actual,
            'cashback'          => $cashback,
            'subvention'        => $subvention,
            'docking'           => $docking,
            'count_achievement' => $countAchievement,
            'percentage'        => $percentage,
            'incentive'         => $this->getIncentive(
                $countAchievement
            ),
        ];
    }



    public function getIncentive(float $countAchievement): float
    {
        $incentive = 0;

        foreach ($this->getIncentiveSlabs() as $target => $amount) {

            if ($countAchievement >= $target) {
                $incentive = $amount;
            }
        }

        return $incentive;
    }


    public function getIncentiveSlabs(): array
    {
        return [
            2500000  => 4000,
            3000000  => 5500,
            3500000  => 7000,
            4000000  => 9000,
            4500000  => 12000,
            5000000  => 15000,
            5500000  => 18000,
            6000000  => 22000,
            6500000  => 26000,
            7000000  => 30000,
            7500000  => 35000,
            8000000  => 40000,
            8500000  => 45000,
            9000000  => 50000,
            9500000  => 55000,
            10000000 => 60000,
            10500000 => 65000,
            11000000 => 70000,
        ];
    }

    public function getNextIncentiveSlab(float $countAchievement): ?array
    {
        foreach ($this->getIncentiveSlabs() as $target => $incentive) {

            if ($countAchievement < $target) {
                return [
                    'target'    => (float) $target,
                    'incentive' => (float) $incentive,
                    'remaining' => max($target - $countAchievement, 0),
                ];
            }
        }

        return null;
    }


    private function getCallerTarget(Employee $employee): float
    {

        /*
    |--------------------------------------------------------------------------
    | CALLER OWN TARGET
    |--------------------------------------------------------------------------
    |
    | Caller target is ALWAYS based on assigned category.
    |
    | DOJ, reporting_date, joining date and exit date do NOT affect
    | the caller's own target.
    |
    */

        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
    }


    public function getHierarchyCallerTarget(Employee $employee): float
    {
        $today = Carbon::today();

        /*
    |--------------------------------------------------------------------------
    | INACTIVE / EXITED EMPLOYEE
    |--------------------------------------------------------------------------
    */

        if (
            strtolower((string) $employee->exit_status) === 'yes'
            && !empty($employee->exit_date)
        ) {
            $exitDate = Carbon::parse($employee->exit_date);

            // Exited during current month
            if (
                $exitDate->year === $today->year &&
                $exitDate->month === $today->month
            ) {
                return $exitDate->day >= 10
                    ? 1500000
                    : 0;
            }

            // Exited before current month
            if ($exitDate->lt($today->copy()->startOfMonth())) {
                return 0;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | CATEGORY TARGET
    |--------------------------------------------------------------------------
    */

        $categoryTarget = is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;

        /*
    |--------------------------------------------------------------------------
    | NO REPORTING DATE
    |--------------------------------------------------------------------------
    */

        if (empty($employee->reporting_date)) {
            return $categoryTarget;
        }

        $reportingDate = Carbon::parse($employee->reporting_date);

        /*
    |--------------------------------------------------------------------------
    | REPORTING STARTED IN CURRENT MONTH
    |--------------------------------------------------------------------------
    */

        if (
            $reportingDate->month === $today->month &&
            $reportingDate->year === $today->year
        ) {
            $workedDays = $reportingDate->diffInDays($today) + 1;

            return $workedDays >= 10
                ? 1500000
                : 0;
        }

        /*
    |--------------------------------------------------------------------------
    | EXISTING ACTIVE EMPLOYEE
    |--------------------------------------------------------------------------
    */

        return $categoryTarget;
    }
}
