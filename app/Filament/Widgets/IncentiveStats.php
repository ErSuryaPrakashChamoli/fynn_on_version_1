<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\IncentiveCalculator;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;

class IncentiveStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        $isAdmin = $user->hasRole('Admin');

        /*
        |--------------------------------------------------------------------------
        | Calculate Incentive
        |--------------------------------------------------------------------------
        */

        $data = IncentiveCalculator::calculate($employee);

        /*
        |--------------------------------------------------------------------------
        | Currency
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
        | Values
        |--------------------------------------------------------------------------
        */

        $cashback = (float) ($data['cashback'] ?? 0);

        $subvention = (float) ($data['subvention'] ?? 0);

        $docking = (float) ($data['docking'] ?? 0);

        $currentIncentive = (float) (
            $data['incentive'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            Stat::make(
                $isAdmin
                    ? '💰 Total Company Cashback'
                    : '💰 Cashback',
                $formatter->formatCurrency(
                    $cashback,
                    'INR'
                )
            )
                ->color('success')
                ->description(
                    $isAdmin
                        ? 'Total Company Cashback Deduction'
                        : 'Total Cashback Deduction'
                )
                ->descriptionIcon(
                    'heroicon-m-banknotes'
                )
                ->icon(
                    'heroicon-o-currency-rupee'
                ),

            Stat::make(
                $isAdmin
                    ? '🏦 Total Company Subvention'
                    : '🏦 Subvention',
                $formatter->formatCurrency(
                    $subvention,
                    'INR'
                )
            )
                ->color('warning')
                ->description(
                    $isAdmin
                        ? 'Total Company Subvention'
                        : 'Total Subvention'
                )
                ->descriptionIcon(
                    'heroicon-m-building-library'
                )
                ->icon(
                    'heroicon-o-building-library'
                ),

            Stat::make(
                $isAdmin
                    ? '⚓ Total Company Docking'
                    : '⚓ Docking',
                $formatter->formatCurrency(
                    $docking,
                    'INR'
                )
            )
                ->color('danger')
                ->description(
                    $isAdmin
                        ? 'Total Company Docking Charges'
                        : 'Docking Charges'
                )
                ->descriptionIcon(
                    'heroicon-m-arrow-down-circle'
                )
                ->icon(
                    'heroicon-o-arrow-down-circle'
                ),

            Stat::make(
                $isAdmin
                    ? '🏆 Total Company Incentive'
                    : '🏆 Earned Incentive',
                $formatter->formatCurrency(
                    $currentIncentive,
                    'INR'
                )
            )
                ->color('success')
                ->description(
                    $isAdmin
                        ? 'Total Incentive for Company'
                        : 'Current Incentive Earned'
                )
                ->descriptionIcon(
                    'heroicon-m-trophy'
                )
                ->icon(
                    'heroicon-o-trophy'
                ),
        ];
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user && (
            $user->hasRole('Admin')
            || $user->employee?->designation === Employee::DESIGNATION_CALLER
        );
    }
}
