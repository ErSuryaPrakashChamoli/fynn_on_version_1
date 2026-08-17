<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use Carbon\Carbon;
use App\Services\HierarchyService;
use App\Support\HierarchyHelper;

class AchievementCalculatorService
{


    // public function getCountAchievement(Employee $employee): float
    // {
    //     if ($employee->designation === Employee::DESIGNATION_ADMIN) {

    //         $customers = Customer::query();
    //     } else {

    //         $employeeIds = HierarchyHelper::subordinateIds($employee);

    //         $customers = Customer::query()
    //             ->whereIn('employee_id', $employeeIds);
    //     }

    //     $customers
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year);

    //     $achievement = (float) $customers->sum('sanctioned_loan_amount');
    //     $cashback = (float) $customers->sum('cashback');
    //     $subvention = (float) $customers->sum('subvention');
    //     $docking = (float) $customers->sum('docking');

    //     return $achievement
    //         - ((($cashback + $subvention + $docking) / 2) * 100);
    // }


    public function getCountAchievement(Employee $employee): float
    {
        /*
    |--------------------------------------------------------------------------
    | Customer Scope
    |--------------------------------------------------------------------------
    |
    | Always calculate based on the employee being evaluated.
    |
    | Caller
    |     -> Caller customers
    |
    | Team Leader
    |     -> Team Leader + Caller customers
    |
    | Manager
    |     -> Manager + TL + Caller customers
    |
    | Cluster Manager
    |     -> Cluster + Manager + TL + Caller customers
    |
    | Admin
    |     -> Company-wide only when an Admin employee record
    |        itself is being evaluated.
    |
    */

        if ($employee->designation === Employee::DESIGNATION_ADMIN) {
            $customers = Customer::query();
        } else {
            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customers = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        $customers
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $achievement = (float) $customers->sum('sanctioned_loan_amount');
        $cashback = (float) $customers->sum('cashback');
        $subvention = (float) $customers->sum('subvention');
        $docking = (float) $customers->sum('docking');

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
                ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

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
                ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

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
                ->sum(fn(Employee $caller) => $this->getCallerTarget($caller));

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

    // public function getPerformance(?Employee $employee): array
    // {
    //     $isAdmin = auth()->user()?->hasRole('Admin');

    //     /*
    // |--------------------------------------------------------------------------
    // | Customer Scope
    // |--------------------------------------------------------------------------
    // */

    //     if ($isAdmin) {

    //         // Admin sees ALL customers, including employee_id = NULL.
    //         $customers = Customer::query();
    //     } else {

    //         if (! $employee) {
    //             return [
    //                 'target_category'   => null,
    //                 'target'            => 0,
    //                 'actual'            => 0,
    //                 'cashback'          => 0,
    //                 'subvention'        => 0,
    //                 'docking'           => 0,
    //                 'count_achievement' => 0,
    //                 'percentage'        => 0,
    //                 'incentive'         => 0,
    //             ];
    //         }

    //         $employeeIds = HierarchyHelper::subordinateIds($employee);

    //         $customers = Customer::query()
    //             ->whereIn('employee_id', $employeeIds);
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Current Month
    //     |--------------------------------------------------------------------------
    //     */

    //     $customers
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Actual / Deductions
    //     |--------------------------------------------------------------------------
    //     */

    //     $totals = $customers
    //         ->selectRaw("
    //         SUM(sanctioned_loan_amount) as actual,
    //         SUM(cashback) as cashback,
    //         SUM(subvention) as subvention,
    //         SUM(docking) as docking
    //     ")
    //         ->first();

    //     $actual = (float) ($totals->actual ?? 0);
    //     $cashback = (float) ($totals->cashback ?? 0);
    //     $subvention = (float) ($totals->subvention ?? 0);
    //     $docking = (float) ($totals->docking ?? 0);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Count Achievement
    //     |--------------------------------------------------------------------------
    //     */

    //     $countAchievement = $actual
    //         - ((($cashback + $subvention + $docking) / 2) * 100);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Target
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($isAdmin) {

    //         $target = Employee::where(
    //             'designation',
    //             Employee::DESIGNATION_CALLER
    //         )
    //             ->get()
    //             ->sum(
    //                 fn(Employee $caller) =>
    //                 $this->getCallerTarget($caller)
    //             );
    //     } else {

    //         $target = $this->getTarget($employee);
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Percentage
    //     |--------------------------------------------------------------------------
    //     */

    //     $percentage = $target > 0
    //         ? round(($countAchievement / $target) * 100, 2)
    //         : 0;

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Result
    //     |--------------------------------------------------------------------------
    //     */

    //     return [
    //         'target_category'   => $employee?->category,
    //         'target'            => (float) $target,
    //         'actual'             => $actual,
    //         'cashback'           => $cashback,
    //         'subvention'         => $subvention,
    //         'docking'            => $docking,
    //         'count_achievement' => $countAchievement,
    //         'percentage'        => $percentage,
    //         'incentive'         => $this->getIncentive($countAchievement),
    //     ];
    // }

    public function getPerformance(?Employee $employee): array
    {
        /*
    |--------------------------------------------------------------------------
    | No employee
    |--------------------------------------------------------------------------
    |
    | There is no employee-specific context.
    | Return empty performance instead of guessing.
    |
    */

        if (! $employee) {
            return [
                'target_category'   => null,
                'target'            => 0,
                'actual'            => 0,
                'cashback'          => 0,
                'subvention'        => 0,
                'docking'           => 0,
                'count_achievement' => 0,
                'percentage'        => 0,
                'incentive'         => 0,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Customer Scope
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Do NOT use auth()->user()->hasRole('Admin') here.
    |
    | The calculation must be based on the Employee record
    | passed into this method.
    |
    | Example:
    |
    | Admin logged in
    |     -> getPerformance(Caller A)
    |     -> Caller A data
    |
    | Admin logged in
    |     -> getPerformance(Manager A)
    |     -> Manager A hierarchy data
    |
    */

        if ($employee->designation === Employee::DESIGNATION_ADMIN) {

            // Only an actual Admin employee record gets company-wide data.
            $customers = Customer::query();
        } else {

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
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        /*
    |--------------------------------------------------------------------------
    | Actual / Cashback / Subvention / Docking
    |--------------------------------------------------------------------------
    */

        $totals = $customers
            ->selectRaw("
            SUM(sanctioned_loan_amount) as actual,
            SUM(cashback) as cashback,
            SUM(subvention) as subvention,
            SUM(docking) as docking
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
    |
    | Target belongs to the employee being displayed.
    |
    | Do NOT calculate Admin target simply because the
    | logged-in user is Admin.
    |
    */

        $target = $this->getTarget($employee);

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
            'target_category'   => $employee->category,
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
        $today = Carbon::today();

        $currentMonth = $today->month;
        $currentYear  = $today->year;

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
        */

        if (
            $joiningDate->month == $currentMonth &&
            $joiningDate->year == $currentYear
        ) {
            $workedDays = $joiningDate->diffInDays($today) + 1;

            // 10 or more days = 15 Lakh
            if ($workedDays >= 10) {
                return 1500000;
            }

            // Less than 10 days = employee category
            return is_numeric($employee->category)
                ? (float) $employee->category
                : 2500000;
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING EMPLOYEE
        |--------------------------------------------------------------------------
        */

        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
    }


    // private function getCallerTarget(Employee $employee): float
    // {
    //     $today = Carbon::today();

    //     $currentMonth = $today->month;
    //     $currentYear  = $today->year;
    //     $monthEnd     = $today->copy()->endOfMonth();

    //     /*
    // |--------------------------------------------------------------------------
    // | Employee must have DOJ
    // |--------------------------------------------------------------------------
    // */

    //     if (empty($employee->doj)) {
    //         return 0;
    //     }

    //     $joiningDate = Carbon::parse($employee->doj);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | EXIT EMPLOYEE
    //     |--------------------------------------------------------------------------
    //     |
    //     | If employee exited in current month,
    //     | calculate target based on actual working days.
    //     |
    //     */


    //     if (
    //         $employee->exit_status === 'yes' &&
    //         !empty($employee->exit_date)
    //     ) {
    //         $exitDate = Carbon::parse($employee->exit_date);

    //         /*
    //         |-------------------------------------------------------------
    //         | Employee exited before current month
    //         |-------------------------------------------------------------
    //         */

    //         if ($exitDate->lt($today->copy()->startOfMonth())) {
    //             return 0;
    //         }

    //         /*
    //         |-------------------------------------------------------------
    //         | Employee exits during current month
    //         |-------------------------------------------------------------
    //         */

    //         if (
    //             $exitDate->month == $currentMonth &&
    //             $exitDate->year == $currentYear
    //         ) {

    //             $workedDays = $today->copy()->startOfMonth()->diffInDays($exitDate) + 1;

    //             return $workedDays >= 10
    //                 ? 1500000
    //                 : 0;
    //         }
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | NEW JOINER
    //     |--------------------------------------------------------------------------
    //     |
    //     | Only DOJ determines whether employee is a new joiner.
    //     | Reporting date is ignored.
    //     |
    //     */

    //     if (
    //         $joiningDate->month == $currentMonth &&
    //         $joiningDate->year == $currentYear
    //     ) {

    //         $remainingDays = $joiningDate->diffInDays($monthEnd) + 1;

    //         return $remainingDays >= 10
    //             ? 1500000
    //             : 0;
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | EXISTING EMPLOYEE
    //     |--------------------------------------------------------------------------
    //     |
    //     | Existing employees always carry full target,
    //     | even if reporting manager/TL changes.
    //     |
    //     */

    //     return is_numeric($employee->category)
    //         ? (float) $employee->category
    //         : 2500000;
    // }
}
