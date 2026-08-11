<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;

class ManagerPPPStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getHeading(): ?string
    {
        return '📊 Manager Performance Stats';
    }

    protected function getDescription(): ?string
    {
        return 'Monitor caller eligibility, PPP performance, multiplier, and manager incentive.';
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        /*
        |--------------------------------------------------------------------------
        | Manager Only
        |--------------------------------------------------------------------------
        */

        if (
            ! $employee ||
            $employee->designation !== Employee::DESIGNATION_MANAGER
        ) {
            return [];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculator
        |--------------------------------------------------------------------------
        */

        $calculator = app(AchievementCalculatorService::class);

        /*
        |--------------------------------------------------------------------------
        | Count Achievement
        |--------------------------------------------------------------------------
        */

        $countAchievement = $calculator->getCountAchievement(
            $employee
        );

        /*
        |--------------------------------------------------------------------------
        | Eligible Callers
        |--------------------------------------------------------------------------
        */

        $eligibleCallers = $calculator->getEligibleCallerCount(
            $employee
        );

        /*
        |--------------------------------------------------------------------------
        | PPP
        |--------------------------------------------------------------------------
        */

        $ppp = $eligibleCallers > 0
            ? $countAchievement / $eligibleCallers
            : 0;

        /*
        |--------------------------------------------------------------------------
        | PPP Multiplier
        |--------------------------------------------------------------------------
        */

        $multiplier = $calculator->getPPPMultiplier($ppp);

        /*
        |--------------------------------------------------------------------------
        | Manager Incentive
        |--------------------------------------------------------------------------
        */

        $managerIncentive = $countAchievement * $multiplier;

        /*
        |--------------------------------------------------------------------------
        | Formatter
        |--------------------------------------------------------------------------
        */

        $formatter = new NumberFormatter(
            'en_IN',
            NumberFormatter::CURRENCY
        );

        $formatter->setAttribute(
            NumberFormatter::MAX_FRACTION_DIGITS,
            0
        );

        /*
        |--------------------------------------------------------------------------
        | Multiplier %
        |--------------------------------------------------------------------------
        */

        $multiplierPercentage = number_format(
            $multiplier * 100,
            3
        ) . '%';

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            Stat::make(
                '👥 Eligible Callers',
                $eligibleCallers
            )
                ->description('Callers eligible for PPP')
                ->descriptionIcon('heroicon-m-users')
                ->icon('heroicon-o-user-group')
                ->color('primary')
                ->extraAttributes([
                    'class' => 'performance-card manager-ppp-callers',
                ]),
            Stat::make(
                '📊 PPP',
                $formatter->formatCurrency($ppp, 'INR')
            )
                ->description('Average Count Achievement per Caller')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->extraAttributes([
                    'class' => 'performance-card manager-ppp-value',
                ]),
            Stat::make(
                '⚡ Multiplier',
                $multiplierPercentage
            )
                ->description(
                    $ppp > 0
                        ? 'Applicable PPP multiplier'
                        : 'No multiplier applicable'
                )
                ->descriptionIcon('heroicon-m-bolt')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color(
                    $multiplier > 0
                        ? 'warning'
                        : 'danger'
                )
                ->extraAttributes([
                    'class' => 'performance-card manager-ppp-multiplier',
                ]),

            Stat::make(
                '🏆 Manager Incentive',
                $formatter->formatCurrency(
                    $managerIncentive,
                    'INR'
                )
            )
                ->description('Estimated Manager Incentive')
                ->descriptionIcon('heroicon-m-trophy')
                ->icon('heroicon-o-trophy')
                ->color(
                    $managerIncentive > 0
                        ? 'success'
                        : 'gray'
                )
                ->extraAttributes([
                    'class' => 'performance-card manager-ppp-incentive',
                ]),
        ];
    }

    public static function canView(): bool
    {
        $employee = Filament::auth()->user()?->employee;

        return $employee
            && $employee->designation === Employee::DESIGNATION_MANAGER;
    }
}
