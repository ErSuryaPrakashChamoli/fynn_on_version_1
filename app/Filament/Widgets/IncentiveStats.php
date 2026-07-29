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

        // $query = Customer::query()
        //         ->whereMonth('created_at', now()->month)
        //         ->whereYear('created_at', now()->year);

        //     if (! $user->hasRole('Admin')) {
        //         $query->where('employee_id', $employee->id);
        //     }



// $query = Customer::query()
//     ->whereMonth('created_at', now()->month)
//     ->whereYear('created_at', now()->year);


            // $employeeIds = $this->getEmployeeIds($employee, $user);


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
                $nextIncentive = null;

                foreach ($slabs as $volume => $incentive) {

                    if ($countAchievement >= $volume) {
                        $currentIncentive = $incentive;
                    } else {

                        $nextVolume = $volume;
                        $nextIncentive = $incentive;

                        break;
                    }
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

if (! $user->hasRole('Manager')) {

    $stats[] = Stat::make(
        '🎯 Earned Incentive',
        $indianCurrencyFormatter->formatCurrency($currentIncentive, 'INR')
    )
        ->color('success')
         ->descriptionIcon('heroicon-m-trophy')
          ->icon('heroicon-o-trophy')
        ->description('Current Incentive Earned');
}

// $stats[] = Stat::make(
//     '🎯 Earned Incentive',
//     $indianCurrencyFormatter->formatCurrency($currentIncentive, 'INR')
// )
//     ->color('success')
//     ->description('Current Incentive Earned')
//     ->descriptionIcon('heroicon-m-trophy')
//     ->icon('heroicon-o-trophy');

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
}
