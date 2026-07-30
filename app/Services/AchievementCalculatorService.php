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
    //     $teamLeaderIds = Employee::where('manager_id', $employee->id)
    //         ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
    //         ->pluck('id')
    //         ->toArray();

    //     $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
    //         ->where('designation', Employee::DESIGNATION_CALLER)
    //         ->pluck('id')
    //         ->toArray();

    //     $directCallers = Employee::where('manager_id', $employee->id)
    //         ->where('designation', Employee::DESIGNATION_CALLER)
    //         ->pluck('id')
    //         ->toArray();

    //     $allSubordinateIds = array_unique(array_merge(
    //         $teamLeaderIds,
    //         $callerIds,
    //         $directCallers
    //     ));

    //     $achievement = Customer::whereIn('employee_id', $allSubordinateIds)
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('sanctioned_loan_amount');

    //     $cashback = Customer::whereIn('employee_id', $allSubordinateIds)
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('cashback');

    //     $subvention = Customer::whereIn('employee_id', $allSubordinateIds)
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('subvention');

    //     $docking = Customer::where('employee_id', $employee->id)
    //         ->whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('docking');

    //     return $achievement - ((($cashback + $subvention + $docking) / 2) * 100);
    // }


    public function getCountAchievement(Employee $employee): float
    {
        // $employeeIds = HierarchyService::subordinateEmployeeIds($employee);
        $employeeIds = HierarchyHelper::subordinateIds($employee);

        $customers = Customer::whereIn('employee_id', $employeeIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $achievement = (float) $customers->sum('sanctioned_loan_amount');
        $cashback = (float) $customers->sum('cashback');
        $subvention = (float) $customers->sum('subvention');
        $docking = (float) $customers->sum('docking');

        return $achievement - ((($cashback + $subvention + $docking) / 2) * 100);
    }

    // public function getTarget(Employee $employee): float
    // {
    //     return is_numeric($employee->category)
    //         ? (float) $employee->category
    //         : 2500000;
    // }


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
        $today = now();

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        /*
    |--------------------------------------------------------------------------
    | Team Leaders under Manager
    |--------------------------------------------------------------------------
    */

        $teamLeaderIds = Employee::where('manager_id', $manager->id)
            ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
            ->pluck('id');

        /*
    |--------------------------------------------------------------------------
    | Callers under Team Leaders
    |--------------------------------------------------------------------------
    */

        $callers = Employee::whereIn('superviser_id', $teamLeaderIds)
            ->where('designation', Employee::DESIGNATION_CALLER)
            ->where('exit_status', 'no')
            ->get();

        $eligible = 0;

        foreach ($callers as $caller) {

            /*
        |--------------------------------------------------------------------------
        | Existing Caller
        |--------------------------------------------------------------------------
        */

            if (!empty($caller->doj)) {

                $joiningDate = Carbon::parse($caller->doj);

                if ($joiningDate->lt($monthStart)) {
                    $eligible++;
                    continue;
                }
            }

            /*
        |--------------------------------------------------------------------------
        | New Joiner
        |--------------------------------------------------------------------------
        */

            if (!empty($caller->reporting_date)) {

                $reportingDate = Carbon::parse($caller->reporting_date);

                if (
                    $reportingDate->month == $today->month &&
                    $reportingDate->year == $today->year
                ) {

                    $workedDays = $reportingDate->diffInDays($monthEnd) + 1;

                    if ($workedDays >= 10) {
                        $eligible++;
                    }
                }
            }
        }

        return $eligible;
    }

    public function getPPPMultiplier(float $ppp): float
    {
        return match (true) {

            $ppp >= 31 => 0.00075,

            $ppp >= 29 => 0.00060,

            $ppp >= 27 => 0.00050,

            $ppp >= 25 => 0.00040,

            $ppp >= 23 => 0.00030,

            default => 0,
        };
    }


    public function getPerformance(Employee $employee): array
    {
        // $employeeIds = HierarchyService::subordinateEmployeeIds($employee);
        $employeeIds = HierarchyHelper::subordinateIds($employee);

        $customers = Customer::whereIn('employee_id', $employeeIds)
            // ->where('employee_id', $employee->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        // $actual = (float) $customers->sum('sanctioned_loan_amount');
        // $cashback = (float) $customers->sum('cashback');
        // $subvention = (float) $customers->sum('subvention');
        // $docking = (float) $customers->sum('docking');


        $totals = $customers
            ->selectRaw("
        SUM(sanctioned_loan_amount) as actual,
        SUM(cashback) as cashback,
        SUM(subvention) as subvention,
        SUM(docking) as docking
    ")
            ->first();


        $countAchievement = $this->getCountAchievement($employee);


        return [
            'target_category'   => $employee->category,
            'target'            => $this->getTarget($employee),
            'actual'            => (float) $totals->actual,
            'cashback'          => (float) $totals->cashback,
            'subvention'        => (float) $totals->subvention,
            'docking'           => (float) $totals->docking,
            'count_achievement' => $countAchievement,
            'percentage'        => $this->getPercentage($employee),
            'incentive'         => $this->getIncentive($countAchievement),
        ];



        // $countAchievement = $actual - ((($cashback + $subvention + $docking) / 2) * 100);

        // $target = $this->getTarget($employee);

        // return [
        //     'target_category' => 'Monthly',
        //     'target' => $target,
        //     'actual' => $actual,
        //     'cashback' => $cashback,
        //     'subvention' => $subvention,
        //     'docking' => $docking,
        //     'count_achievement' => $countAchievement,
        //     'percentage' => $target > 0
        //         ? round(($countAchievement / $target) * 100, 2)
        //         : 0,
        //     'incentive' => $this->getIncentive($countAchievement),
        // ];
    }


    public function getIncentive(float $countAchievement): float
    {
        $slabs = [
            2500000 => 4000,
            3000000 => 5000,
            3500000 => 6000,
            4000000 => 7000,
            4500000 => 8000,
            5000000 => 9000,
            5500000 => 10000,
            6000000 => 12000,
            7000000 => 15000,
            8000000 => 20000,
            9000000 => 30000,
            10000000 => 50000,
            11000000 => 70000,
        ];

        $incentive = 0;

        foreach ($slabs as $target => $amount) {
            if ($countAchievement >= $target) {
                $incentive = $amount;
            }
        }

        return $incentive;
    }


    // private function getCallerTarget(Employee $employee): float
    // {
    //     $today = Carbon::now();

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

    //     /*
    // |--------------------------------------------------------------------------
    // | Effective Date
    // |--------------------------------------------------------------------------
    // | Reporting Date takes priority.
    // | Otherwise use DOJ.
    // |--------------------------------------------------------------------------
    // */

    //     $joiningDate = Carbon::parse($employee->doj);

    //     $effectiveDate = !empty($employee->reporting_date)
    //         ? Carbon::parse($employee->reporting_date)
    //         : $joiningDate;

    //     /*
    // |--------------------------------------------------------------------------
    // | EXIT EMPLOYEE (Exited in Current Month)
    // |--------------------------------------------------------------------------
    // */

    //     if (
    //         $employee->exit_status === 'yes' &&
    //         !empty($employee->exit_date)
    //     ) {

    //         $exitDate = Carbon::parse($employee->exit_date);

    //         if (
    //             $exitDate->month == $currentMonth &&
    //             $exitDate->year == $currentYear
    //         ) {

    //             // Invalid case
    //             if ($exitDate->lt($effectiveDate)) {
    //                 return 0;
    //             }

    //             $workedDays = $effectiveDate->diffInDays($exitDate) + 1;

    //             return $workedDays >= 10
    //                 ? 1500000
    //                 : 0;
    //         }
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | New Joiner / Reporting Changed This Month
    // |--------------------------------------------------------------------------
    // */

    //     if (
    //         $effectiveDate->month == $currentMonth &&
    //         $effectiveDate->year == $currentYear
    //     ) {

    //         $remainingDays = $effectiveDate->diffInDays($monthEnd) + 1;

    //         return $remainingDays >= 10
    //             ? 1500000
    //             : 0;
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | Existing Employee
    // |--------------------------------------------------------------------------
    // */

    //     return is_numeric($employee->category)
    //         ? (float) $employee->category
    //         : 2500000;
    // }


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
