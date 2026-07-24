<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use App\Models\Customer;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use NumberFormatter;
use App\Models\EmployeeReportingHistory;



class TargetStats extends StatsOverviewWidget
{

    protected static ?int $sort = 1;



protected function getStats(): array
{
    $loginUser = auth()->user();

    $target = 0;
    $achievement = 0;
    $totalCashback = 0;
    $totalSubvention = 0;
    $docking = 0;

    $targetLevel = '🥈 Target Calculation';
    $targetColor = 'info';

    /*
    |--------------------------------------------------------------------------
    | ADMIN DASHBOARD
    |--------------------------------------------------------------------------
    */
    // if ($loginUser->hasRole('Admin')) {

    //     $achievement = Customer::whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('sanctioned_loan_amount');

    //     $totalCashback = Customer::whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('cashback');

    //     $totalSubvention = Customer::whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('subvention');

    //     $docking = Customer::whereMonth('created_at', now()->month)
    //         ->whereYear('created_at', now()->year)
    //         ->sum('docking');

    //     $target = Employee::where('designation', '1')
    //         ->get()
    //         ->sum(fn ($emp) => $this->getCallerTarget($emp));

    //     $targetLevel = '🏢 Company Target';
    //     $targetColor = 'success';
    // }


    if ($loginUser->hasRole('Admin')) {

        $achievement = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('sanctioned_loan_amount');

        $totalCashback = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('cashback');

        $totalSubvention = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('subvention');

        $docking = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('docking');

        $target = Employee::where('designation', Employee::DESIGNATION_CALLER)
            ->get()
            ->sum(fn (Employee $emp) => $this->getCallerTarget($emp));

        $targetLevel = '🏢 Company Target';
        $targetColor = 'success';
 }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE DASHBOARD
    |--------------------------------------------------------------------------
    */
    else {

        $employee = Employee::find($loginUser->employee_id);

        if (! $employee) {
            return [];
        }

        $designation = $employee->designation;



           if ($designation === Employee::DESIGNATION_CALLER) {
                // --- 1. CALLER LOGIC ---
                // $target = is_numeric($employee->category) ? (float) $employee->category : 2500000;
                $target = $this->getCallerTarget($employee);



                $achievement = Customer::where('employee_id', $employee->id)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('sanctioned_loan_amount');

                $totalCashback = Customer::where('employee_id', $employee->id)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('cashback');

                $totalSubvention = Customer::where('employee_id', $employee->id)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('subvention');

                $docking = Customer::where('employee_id', $employee->id)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('docking');

                if ($target >= 3500000) {
                    $targetLevel = '💎 Diamond Target';
                    $targetColor = 'success';
                } elseif ($target >= 3000000) {
                    $targetLevel = '🥇 Gold Target';
                    $targetColor = 'warning';
                } else {
                    $targetLevel = '🥈 Silver Target';
                    $targetColor = 'info';
                }

            } elseif ($designation == Employee::DESIGNATION_TEAM_LEADER) {
                // --- 2. TEAM LEADER LOGIC ---
                $callerIds = Employee::where('superviser_id', $employee->id)->pluck('id')->toArray();
                $callerCount = count($callerIds);
                $baseTarget = Employee::whereIn('id', $callerIds)
                            ->where('designation', Employee::DESIGNATION_CALLER)
                            ->get()
                            ->sum(fn ($emp) => $this->getCallerTarget($emp));

                if ($callerCount < 3) {
                    $target = $baseTarget + 3000000;
                    $targetLevel = "👥 TL (< 3 Callers: +₹30L Penalty Included)";
                    $targetColor = 'danger';
                } else {
                    $target = $baseTarget;
                    $targetLevel = '👥 Team Leader (Callers Sum)';
                    $targetColor = 'primary';
                }

                $achievement = Customer::whereIn('employee_id', $callerIds)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('sanctioned_loan_amount');

                $totalCashback = Customer::whereIn('employee_id', $callerIds)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('cashback');

                $totalSubvention = Customer::whereIn('employee_id', $callerIds)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->sum('subvention');

                $docking = Customer::where('employee_id', $employee->id)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('docking');

            } elseif ($designation == Employee::DESIGNATION_MANAGER) {
                // --- 3. MANAGER LOGIC (STRICT HIERARCHY FIX) ---
                $teamLeaderIds = Employee::where('manager_id', $employee->id)
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')
                    ->toArray();

                $callerIds = [];
                if (!empty($teamLeaderIds)) {
                    $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                        ->where('designation', Employee::DESIGNATION_CALLER)
                        ->pluck('id')
                        ->toArray();
                }
                $directCallers = Employee::where('manager_id', $employee->id)
                    ->where('designation', '1')
                    ->pluck('id')
                    ->toArray();


                $allActiveCallers = array_unique(array_merge($callerIds, $directCallers));
                $allSubordinateIds = array_unique(array_merge($teamLeaderIds, $allActiveCallers));

                if (!empty($allActiveCallers)) {

                    $target = Employee::whereIn('id', $allActiveCallers)
                        ->get()
                         ->sum(fn ($emp) => $this->getCallerTarget($emp));
                        // ->sum(fn($emp) => is_numeric($emp->category) ? (float)$emp->category : 2500000);
                } else {
                    $target = 0;
                }


                foreach ($teamLeaderIds as $tlId) {
                    $tlCallerCount = Employee::where('superviser_id', $tlId)->where('designation', '1')->count();
                    if ($tlCallerCount < 3) {
                        $target += 3000000;
                    }
                }

                if (!empty($allSubordinateIds)) {
                    $achievement = Customer::whereIn('employee_id', $allSubordinateIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('sanctioned_loan_amount');

                    $totalCashback = Customer::whereIn('employee_id', $allSubordinateIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('cashback');

                    $totalSubvention = Customer::whereIn('employee_id', $allSubordinateIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('subvention');

                $docking = Customer::where('employee_id', $employee->id)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('docking');
                }

                $targetLevel = '👔 Manager (Subordinates + TL Penalties)';
                $targetColor = 'warning';

            } elseif ($designation == Employee::DESIGNATION_CLUSTER) {
                // --- 4. CLUSTER MANAGER LOGIC ---
            //    dd("call");
                $managerIds = Employee::where('cluster_id', $employee->id)
                // ->where('designation', '3')
                ->where('designation', Employee::DESIGNATION_MANAGER)
                ->pluck('id')->toArray();
                $teamLeaderIds = [];
                if (!empty($managerIds)) {
                    $teamLeaderIds = Employee::whereIn('manager_id', $managerIds)
                    // ->where('designation', '2')
                    ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                    ->pluck('id')->toArray();
                }

                $callerIds = [];
                if (!empty($teamLeaderIds)) {
                    $callerIds = Employee::whereIn('superviser_id', $teamLeaderIds)
                    // ->where('designation', '1')
                    ->where('designation', Employee::DESIGNATION_CALLER)
                    ->pluck('id')->toArray();
                }

                $allTeamIds = array_merge($managerIds, $teamLeaderIds, $callerIds);

                if (!empty($callerIds)) {
                    $target = Employee::whereIn('id', $callerIds)
                        ->get()
                         ->sum(fn ($emp) => $this->getCallerTarget($emp));
                        // ->sum(fn($emp) => is_numeric($emp->category) ? (float)$emp->category : 2500000);
                } else {
                    $target = 0;
                }

                foreach ($teamLeaderIds as $tlId) {
                    $tlCallerCount = Employee::where('superviser_id', $tlId)->where('designation', '1')->count();
                    if ($tlCallerCount < 3) {
                        $target += 3000000;
                    }
                }



                if (!empty($allTeamIds)) {
                    $achievement = Customer::whereIn('employee_id', $allTeamIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('sanctioned_loan_amount');

                    $totalCashback = Customer::whereIn('employee_id', $allTeamIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('cashback');

                    $totalSubvention = Customer::whereIn('employee_id', $allTeamIds)
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('subvention');

                    $docking = Customer::where('employee_id', $employee->id)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum('docking');

                }

                $targetLevel = '🏢 Cluster Manager (Total Hierarchy Sum)';
                $targetColor = 'danger';
            }

    }

    $countAchievement = $achievement - ((($totalCashback + $totalSubvention + $docking) / 2) * 100);
    $pending = max(0, $target - $countAchievement);
    $remainingDays = max(1, now()->daysInMonth - now()->day);
    $drr = $pending / $remainingDays;

    $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
    $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

    return [
        Stat::make('🎯 Target', $formatter->formatCurrency($target, 'INR'))
            ->description($targetLevel)
            ->color($targetColor),

        Stat::make('💰 Actual Achievement', $formatter->formatCurrency($achievement, 'INR'))
            ->description('Disbursed Loan Volume')
            ->color('success'),

        Stat::make('📊 Count Achievement', $formatter->formatCurrency($countAchievement, 'INR'))
            ->description('Adjusted Net Volume')
            ->color('primary'),

        Stat::make('⏳ Pending Target', $formatter->formatCurrency($pending, 'INR'))
            ->description('Remaining Target')
            ->color($pending > 0 ? 'warning' : 'success'),

        Stat::make('📈 DRR', $formatter->formatCurrency($drr, 'INR'))
            ->description('Daily Required  Rate')
            ->color($drr > 0 ? 'danger' : 'success'),
    ];
}


    private function getCallerTarget(Employee $employee): float
        {


            $today = Carbon::now();

            $currentMonth = $today->month;
            $currentYear  = $today->year;

            $monthStart = $today->copy()->startOfMonth();
            $monthEnd   = $today->copy()->endOfMonth();

            /*
            |--------------------------------------------------------------------------
            | EXIT EMPLOYEE (ONLY FOR EXITS IN CURRENT MONTH)
            |--------------------------------------------------------------------------
            */

            if (! empty($employee->exit_date)) {

                $exitDate = Carbon::parse($employee->exit_date);

                if (
                    $exitDate->month == $currentMonth &&
                    $exitDate->year == $currentYear
                ) {

                    $workedDays = $monthStart->diffInDays($exitDate) + 1;

                    return $workedDays >= 10
                        ? 1500000
                        : 0;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | NEW JOINER (ONLY IF JOINED THIS MONTH)
            |--------------------------------------------------------------------------
            */

            // if (! empty($employee->reporting_date)) {


            //     $reportingDate = Carbon::parse($employee->reporting_date);

            //     if (
            //         $reportingDate->month == $currentMonth &&
            //         $reportingDate->year == $currentYear
            //     ) {

            //         $remainingDays = $reportingDate->diffInDays($monthEnd) + 1;

            //         return $remainingDays >= 10
            //             ? 1500000
            //             : 0;
            //     }
            // }


    if (! empty($employee->doj)) {

    $joiningDate = Carbon::parse($employee->doj);

    if (
        $joiningDate->month == $currentMonth &&
        $joiningDate->year == $currentYear
    ) {

        /*
        |--------------------------------------------------------------------------
        | New Joiner
        | Reporting date decides when the employee starts reporting.
        |--------------------------------------------------------------------------
        */

        $effectiveDate = ! empty($employee->reporting_date)
            ? Carbon::parse($employee->reporting_date)
            : $joiningDate;

        $remainingDays = $effectiveDate->diffInDays($monthEnd) + 1;

        return $remainingDays >= 10
            ? 1500000
            : 0;
    }
}

            /*
            |--------------------------------------------------------------------------
            | EXISTING EMPLOYEE
            |--------------------------------------------------------------------------
            |
            | Joined before current month.
            | Always take category target.
            | Ignore reporting_date.
            | Ignore reporting history.
            |
            */

            return is_numeric($employee->category)
                ? (float) $employee->category
                : 2500000;
        }


        protected function getAdminStats(): array
            {
                $target = Customer::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum('sanctioned_loan_amount');

                return [
                    Stat::make('Total Business', number_format($target))
                        ->color('success'),
                ];
            }
}
