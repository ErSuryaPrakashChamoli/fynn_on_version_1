<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Employee;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use NumberFormatter;

class IncentiveStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {

        $user = auth()->user();


        // Change this if your User -> Employee relation is different
        $employee = Employee::where('email', $user->email)->first();


        // if (! $employee) {
        //     return [
        //         Stat::make('Incentive', 'Employee Not Found'),
        //     ];
        // }


        if (! $user->hasRole('Admin') && ! $employee) {
            return [
                Stat::make('Incentive', 'Employee Not Found'),
            ];
        }




        if ($user->hasRole('Admin') || $user->hasRole('Cluster Manager')) {

            $employeeIds = Employee::pluck('id');
        } else {

            if (! $employee) {
                return [
                    Stat::make('Incentive', 'Employee Not Found'),
                ];
            }

            $employeeIds = $this->getEmployeeIds($employee, $user);
        }

        $query = Customer::whereIn('employee_id', $employeeIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);



        $actualAchievement = (clone $query)->sum('sanctioned_loan_amount');
        $cashback          = (clone $query)->sum('cashback');
        $subvention        = (clone $query)->sum('subvention');
        $docking           = (clone $query)->sum('docking');





        $countAchievement = $actualAchievement
            - ((($cashback + $subvention + $docking) / 2) * 100);


        $slabs = $this->getSlabs();

        $currentIncentive = 0;
        $nextVolume = null;

        if ($user->hasRole('Caller')) {

            $slabs = $this->getSlabs();

            foreach ($slabs as $volume => $incentive) {

                if ($countAchievement >= $volume) {

                    $currentIncentive = $incentive;
                } else {

                    $nextVolume = $volume;

                    break;
                }
            }
        } elseif ($user->hasRole('Team Leader')) {

            $currentIncentive = $this->calculateTeamLeaderIncentive($employee);
        }



        $indianCurrencyFormatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $indianCurrencyFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        $stats = [];

        $stats[] = Stat::make(
            '💰 Cashback',
            $indianCurrencyFormatter->formatCurrency($cashback, 'INR')
        )
            ->color('success')
            ->description('Total Cashback Deduction')
            ->descriptionIcon('heroicon-m-banknotes')
            ->icon('heroicon-o-currency-rupee');

        $stats[] = Stat::make(
            '🏦 Subvention',
            $indianCurrencyFormatter->formatCurrency($subvention, 'INR')
        )
            ->color('warning')
            ->description('Total Subvention')
            ->descriptionIcon('heroicon-m-building-library')
            ->icon('heroicon-o-building-library');

        $stats[] = Stat::make(
            '⚓ Docking',
            $indianCurrencyFormatter->formatCurrency($docking, 'INR')
        )
            ->color('danger')
            ->description('Docking Charges')
            ->descriptionIcon('heroicon-m-arrow-down-circle')
            ->icon('heroicon-o-arrow-down-circle');

        $stats[] = Stat::make(
            '🎯 Earned Incentive',
            $indianCurrencyFormatter->formatCurrency($currentIncentive, 'INR')
        )
            ->color('success')
            ->description('Current Incentive Earned')
            ->descriptionIcon('heroicon-m-trophy')
            ->icon('heroicon-o-trophy');

        /*
|--------------------------------------------------------------------------
| ONLY CALLER CAN SEE NEXT SLAB
|--------------------------------------------------------------------------
*/

        if ($user->hasRole('Caller')) {

            $stats[] = Stat::make(
                '📈 Next Slab',
                $nextVolume
                    ? $indianCurrencyFormatter->formatCurrency($nextVolume, 'INR')
                    : '🏆 Highest Slab'
            )
                ->color('primary')
                ->description('Next Incentive Target')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->icon('heroicon-o-chart-bar');

            $stats[] = Stat::make(
                '🚀 Unlock Next Slab',
                $nextVolume
                    ? $indianCurrencyFormatter->formatCurrency(
                        max(0, $nextVolume - $countAchievement),
                        'INR'
                    )
                    : $indianCurrencyFormatter->formatCurrency(0, 'INR')
            )
                ->color('warning')
                ->description('Remaining Achievement')
                ->descriptionIcon('heroicon-m-fire')
                ->icon('heroicon-o-rocket-launch');
        }

        return $stats;
    }

    private function getSlabs(): array
    {
        return [

            2500000 => 4000,
            3000000 => 5500,
            3500000 => 7000,
            4000000 => 9000,
            4500000 => 12000,
            5000000 => 15000,
            5500000 => 18000,
            6000000 => 22000,
            6500000 => 26000,
            7000000 => 30000,
            7500000 => 35000,
            8000000 => 40000,
            8500000 => 45000,
            9000000 => 50000,
            9500000 => 55000,
            10000000 => 60000,
            10500000 => 65000,
            11000000 => 70000,

        ];
    }

    public static function canView(): bool
    {
        // return ! auth()->user()->hasRole('Admin');
        return true;
    }


    protected function getEmployeeIds(?Employee $employee, $user)
    {

        if (! $employee) {
            return collect();
        }

        if ($user->hasRole('Admin')) {
            return Employee::pluck('id');
        }

        if ($user->hasRole('Cluster Manager')) {
            return Employee::pluck('id');
        }

        if ($user->hasRole('Manager')) {

            $ids = collect([$employee->id]);

            $teamLeaders = Employee::where('manager_id', $employee->id)->pluck('id');

            $ids = $ids->merge($teamLeaders);

            $callers = Employee::whereIn('superviser_id', $teamLeaders)->pluck('id');

            return $ids->merge($callers)->unique();
        }

        if ($user->hasRole('Team Leader')) {

            $ids = collect([$employee->id]);

            $callers = Employee::where('superviser_id', $employee->id)->pluck('id');

            return $ids->merge($callers)->unique();
        }

        return collect([$employee->id]);
    }


    private function calculateTeamLeaderIncentive(Employee $teamLeader): float
    {
        // Active AROs under this TL
        $aros = Employee::where('superviser_id', $teamLeader->id)
            ->where('exit_status', 'no')
            ->get();

        $aroCount = $aros->count();

        if ($aroCount == 0) {
            return 0;
        }


        /*
            |--------------------------------------------------------------------------
            | Minimum Team Size
            |--------------------------------------------------------------------------
            */

        if ($aroCount < 3) {
            return 0;
        }


        /*
        |--------------------------------------------------------------------------
        | Team Target
        |--------------------------------------------------------------------------
        */

        // Example:
        // Every ARO target = 25,00,000

        // $teamTarget = $aroCount * 2500000;
        $teamTarget = 0;

        foreach ($aros as $aro) {
            // $teamTarget += TargetHelper::getCallerTarget(
            //     $aro,
            //     now()->month,
            //     now()->year
            // );

            $teamTarget += $this->getCallerTarget($aro);
        }




        /*
        |--------------------------------------------------------------------------
        | Team Achievement
        |--------------------------------------------------------------------------
        */

        $teamAchievement = Customer::whereIn('employee_id', $aros->pluck('id'))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('sanctioned_loan_amount');

        /*
        |--------------------------------------------------------------------------
        | Achievement %
        |--------------------------------------------------------------------------
        */

        $achievementPercent = $teamTarget > 0
            ? ($teamAchievement / $teamTarget) * 100
            : 0;

        if ($achievementPercent < 90) {
            return 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue
        |--------------------------------------------------------------------------
        */

        $revenue = $teamAchievement * 0.02;

        $targetRevenue = $teamTarget * 0.02;

        $grossRevenue = $revenue - ($aroCount * 30000);

        $baseIncentive =
            ($grossRevenue * 0.08)
            + (($revenue - $targetRevenue) * 0.05);

        /*
        |--------------------------------------------------------------------------
        | Check every ARO achieved target
        |--------------------------------------------------------------------------
        */

        $allAchieved = true;

        foreach ($aros as $aro) {

            $aroAchievement = Customer::where('employee_id', $aro->id)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('sanctioned_loan_amount');

            // if ($aroAchievement < 2500000) {

            //     $allAchieved = false;

            //     break;
            // }


            $aroTarget = $this->getCallerTarget($aro);

            if ($aroAchievement < $aroTarget) {

                $allAchieved = false;

                break;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 10% Bonus
        |--------------------------------------------------------------------------
        */

        if ($aroCount > 3 && $allAchieved) {

            $baseIncentive *= 1.10;
        }

        return round(max($baseIncentive, 0));
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
}
