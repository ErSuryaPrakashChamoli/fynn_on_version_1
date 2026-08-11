<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;

class IncentiveStats extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | Employee
        |--------------------------------------------------------------------------
        */

        $employee = $user?->employee;

        if (! $employee) {
            return [
                Stat::make('Incentive', 'Employee Not Found')
                    ->description('Employee profile is not linked with this user')
                    ->color('danger')
                    ->icon('heroicon-o-exclamation-triangle'),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Achievement Calculator
        |--------------------------------------------------------------------------
        */

        $calculator = app(AchievementCalculatorService::class);

        /*
        |--------------------------------------------------------------------------
        | Performance
        |--------------------------------------------------------------------------
        */

        $performance = $calculator->getPerformance($employee);

        $cashback = (float) ($performance['cashback'] ?? 0);

        $subvention = (float) ($performance['subvention'] ?? 0);

        $docking = (float) ($performance['docking'] ?? 0);

        $countAchievement = (float) (
            $performance['count_achievement'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Current Incentive
        |--------------------------------------------------------------------------
        */

        $currentIncentive = $calculator->getIncentive(
            $countAchievement
        );

        /*
        |--------------------------------------------------------------------------
        | Indian Currency Formatter
        |--------------------------------------------------------------------------
        */

        $indianCurrencyFormatter = new NumberFormatter(
            'en_IN',
            NumberFormatter::CURRENCY
        );

        $indianCurrencyFormatter->setAttribute(
            NumberFormatter::MAX_FRACTION_DIGITS,
            0
        );

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        $stats = [];

        /*
        |--------------------------------------------------------------------------
        | Cashback
        |--------------------------------------------------------------------------
        */

        $stats[] = Stat::make(
            '💰 Cashback',
            $indianCurrencyFormatter->formatCurrency(
                $cashback,
                'INR'
            )
        )
            ->color('success')
            ->description('Total Cashback Deduction')
            ->descriptionIcon('heroicon-m-banknotes')
            ->icon('heroicon-o-currency-rupee');

        /*
        |--------------------------------------------------------------------------
        | Subvention
        |--------------------------------------------------------------------------
        */

        $stats[] = Stat::make(
            '🏦 Subvention',
            $indianCurrencyFormatter->formatCurrency(
                $subvention,
                'INR'
            )
        )
            ->color('warning')
            ->description('Total Subvention')
            ->descriptionIcon('heroicon-m-building-library')
            ->icon('heroicon-o-building-library');

        /*
        |--------------------------------------------------------------------------
        | Docking
        |--------------------------------------------------------------------------
        */

        $stats[] = Stat::make(
            '⚓ Docking',
            $indianCurrencyFormatter->formatCurrency(
                $docking,
                'INR'
            )
        )
            ->color('danger')
            ->description('Docking Charges')
            ->descriptionIcon('heroicon-m-arrow-down-circle')
            ->icon('heroicon-o-arrow-down-circle');

        /*
        |--------------------------------------------------------------------------
        | Earned Incentive
        |--------------------------------------------------------------------------
        */

        if (! $user->hasRole('Manager')) {

            $stats[] = Stat::make(
                '🏆 Earned Incentive',
                $indianCurrencyFormatter->formatCurrency(
                    $currentIncentive,
                    'INR'
                )
            )
                ->color('success')
                ->description('Current Incentive Earned')
                ->descriptionIcon('heroicon-m-trophy')
                ->icon('heroicon-o-trophy');
        }

        /*
        |--------------------------------------------------------------------------
        | Caller Only - Next Slab
        |--------------------------------------------------------------------------
        */

        if (
            $employee->designation === Employee::DESIGNATION_CALLER
        ) {

            $nextSlab = $calculator->getNextIncentiveSlab(
                $countAchievement
            );

            if ($nextSlab) {

                $remaining = $nextSlab['remaining'];

                $nextSlabTarget = $nextSlab['target'];

                $nextSlabIncentive = $nextSlab['incentive'];

                /*
                |--------------------------------------------------------------------------
                | Next Slab
                |--------------------------------------------------------------------------
                */

                $stats[] = Stat::make(
                    '🚀 Next Slab',
                    $indianCurrencyFormatter->formatCurrency(
                        $nextSlabTarget,
                        'INR'
                    )
                )
                    ->color('primary')
                    ->description(
                        'Incentive: ' .
                        $indianCurrencyFormatter->formatCurrency(
                            $nextSlabIncentive,
                            'INR'
                        )
                    )
                    ->descriptionIcon(
                        'heroicon-m-arrow-trending-up'
                    )
                    ->icon(
                        'heroicon-o-chart-bar-square'
                    );

                /*
                |--------------------------------------------------------------------------
                | Unlock Next Slab
                |--------------------------------------------------------------------------
                */

                $stats[] = Stat::make(
                    '🔓 Unlock Next Slab',
                    $indianCurrencyFormatter->formatCurrency(
                        $remaining,
                        'INR'
                    )
                )
                    ->color(
                        $remaining <= 500000
                            ? 'warning'
                            : 'info'
                    )
                    ->description(
                        'Additional achievement required'
                    )
                    ->descriptionIcon(
                        'heroicon-m-lock-open'
                    )
                    ->icon(
                        'heroicon-o-lock-open');
            } else {

                /*
                |--------------------------------------------------------------------------
                | Maximum Slab Achieved
                |--------------------------------------------------------------------------
                */

                $stats[] = Stat::make(
                    '👑 Incentive Level',
                    'Maximum Slab'
                )
                    ->color('success')
                    ->description(
                        'You have reached the highest incentive slab'
                    )
                    ->descriptionIcon(
                        'heroicon-m-trophy'
                    )
                    ->icon(
                        'heroicon-o-trophy'
                    );
            }
        }

        return $stats;
    }
}
