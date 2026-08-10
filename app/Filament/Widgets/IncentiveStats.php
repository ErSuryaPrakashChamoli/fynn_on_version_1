<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IncentiveStats extends BaseWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();

        $employee = $user?->employee;

        if (!$employee) {
            return [
                Stat::make('Incentive', 'Employee Not Found'),
            ];
        }

        $calculator = app(AchievementCalculatorService::class);

        $performance = $calculator->getPerformance($employee);

        return [
            Stat::make(
                'Target',
                indianCurrencyFormat($performance['target'])
            ),

            Stat::make(
                'Actual',
                indianCurrencyFormat($performance['actual'])
            ),

            Stat::make(
                'Count Achievement',
                indianCurrencyFormat($performance['count_achievement'])
            ),

            Stat::make(
                'Achievement',
                number_format($performance['percentage'], 2) . '%'
            ),

            Stat::make(
                'Cashback',
                indianCurrencyFormat($performance['cashback'])
            ),

            Stat::make(
                'Subvention',
                indianCurrencyFormat($performance['subvention'])
            ),

            Stat::make(
                'Docking',
                indianCurrencyFormat($performance['docking'])
            ),

            Stat::make(
                'Earned Incentive',
                indianCurrencyFormat($performance['incentive'])
            ),
        ];
    }
}
