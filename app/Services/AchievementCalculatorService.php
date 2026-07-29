<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use Carbon\Carbon;

class AchievementCalculatorService
{
    public function getCountAchievement(Employee $employee): float
    {
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

        $allSubordinateIds = array_unique(array_merge(
            $teamLeaderIds,
            $callerIds,
            $directCallers
        ));

        $achievement = Customer::whereIn('employee_id', $allSubordinateIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('sanctioned_loan_amount');

        $cashback = Customer::whereIn('employee_id', $allSubordinateIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('cashback');

        $subvention = Customer::whereIn('employee_id', $allSubordinateIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('subvention');

        $docking = Customer::where('employee_id', $employee->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('docking');

        return $achievement - ((($cashback + $subvention + $docking) / 2) * 100);
    }

    public function getTarget(Employee $employee): float
    {
        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
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
}
