<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;
use Filament\Facades\Filament;

class ManagerPPPStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        // $user = auth()->user();
        $user = Filament::auth()->user();


        $employee = Employee::find($user->employee_id);

        if (!$employee || $employee->designation !== Employee::DESIGNATION_MANAGER) {
            return [];
        }

        $calculator = app(AchievementCalculatorService::class);

        $countAchievement = $calculator->getCountAchievement($employee);

        $eligibleCallers = $calculator->getEligibleCallerCount($employee);

        $ppp = $eligibleCallers > 0
            ? $countAchievement / $eligibleCallers
            : 0;

        $multiplier = $calculator->getPPPMultiplier($ppp);

        // $incentive = $countAchievement * $multiplier;

        $managerIncentive = $countAchievement * $multiplier;

        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);



        return [

            Stat::make(
                'Eligible Callers',
                $eligibleCallers
            ),

            Stat::make(
                'PPP',
                $formatter->formatCurrency($ppp, 'INR')
            ),

            Stat::make(
                'Multiplier',
                number_format($multiplier * 100, 3) . '%'
            ),

            Stat::make(
                'Manager Incentive',
                $formatter->formatCurrency($managerIncentive, 'INR')
            )
                ->color('success'),

        ];
    }

    public static function canView(): bool
    {
        // $employee = auth()->user()->employee;
        $employee = Filament::auth()->user()?->employee;

        return $employee
            && $employee->designation === Employee::DESIGNATION_MANAGER;
    }
}
