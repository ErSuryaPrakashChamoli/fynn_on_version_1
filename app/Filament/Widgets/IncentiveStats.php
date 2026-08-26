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
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return 'Net Achievement & Incentive';
    }

    protected function getDescription(): ?string
    {
        return 'Track net achievement, deductions, and incentive earnings against your monthly target.';
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        $isAdmin = $user->hasRole('Admin');

        $isHierarchyLead = ! $isAdmin && in_array($employee?->designation, [
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_CLUSTER,
        ], true);

        /*
        |--------------------------------------------------------------------------
        | Calculate Incentive
        |--------------------------------------------------------------------------
        */

        $data = IncentiveCalculator::calculate(
            $employee
        );

        /*
        |--------------------------------------------------------------------------
        | Currency Formatter
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

        $cashback = (float) (
            $data['cashback'] ?? 0
        );

        $subvention = (float) (
            $data['subvention'] ?? 0
        );

        $docking = (float) (
            $data['docking'] ?? 0
        );

        $currentIncentive = (float) (
            $data['incentive'] ?? 0
        );

        $actual = (float) (
            $data['actual'] ?? 0
        );

        $countAchievement = (float) (
            $data['count_achievement'] ?? 0
        );

        $target = (float) (
            $data['target'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Total Deductions
        |--------------------------------------------------------------------------
        */

        $totalDeductions =
            $cashback
            + $subvention
            + $docking;

        /*
        |--------------------------------------------------------------------------
        | Achievement %
        |--------------------------------------------------------------------------
        */

        $achievementPercentage = $target > 0
            ? round(
                ($countAchievement / $target) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Incentive Status
        |--------------------------------------------------------------------------
        */

        if ($currentIncentive > 0) {

            $incentiveBadge = '🏆 EARNING';

            $incentiveColor = 'success';

            $incentiveDescription = match (true) {
                $isAdmin => 'Company incentive generated',
                $isHierarchyLead => 'Team incentive earned',
                default => 'Current incentive earned',
            };
        } elseif ($countAchievement > 0) {

            $incentiveBadge = '📈 IN PROGRESS';

            $incentiveColor = 'warning';

            $incentiveDescription =
                "{$achievementPercentage}% achievement";
        } else {

            $incentiveBadge = '⚠️ NOT STARTED';

            $incentiveColor = 'danger';

            $incentiveDescription =
                'No incentive generated yet';
        }

        /*
        |--------------------------------------------------------------------------
        | Cashback Status
        |--------------------------------------------------------------------------
        */

        if ($cashback > 0) {

            $cashbackBadge = '💸 DEDUCTION';

            $cashbackColor = 'success';
        } else {

            $cashbackBadge = '✅ NONE';

            $cashbackColor = 'success';
        }

        /*
        |--------------------------------------------------------------------------
        | Subvention Status
        |--------------------------------------------------------------------------
        */

        if ($subvention > 0) {

            $subventionBadge = '🏦 APPLIED';

            $subventionColor = 'warning';
        } else {

            $subventionBadge = '✅ NONE';

            $subventionColor = 'success';
        }

        /*
        |--------------------------------------------------------------------------
        | Docking Status
        |--------------------------------------------------------------------------
        */

        if ($docking > 0) {

            $dockingBadge = '⚓ APPLIED';

            $dockingColor = 'danger';
        } else {

            $dockingBadge = '✅ NONE';

            $dockingColor = 'success';
        }

        /*
        |--------------------------------------------------------------------------
        | Scope
        |--------------------------------------------------------------------------
        */

        $scopeBadge = match (true) {
            $isAdmin => '🏢 COMPANY-WIDE',
            $isHierarchyLead => '👥 YOUR TEAM',
            default => '👤 YOUR INCENTIVE',
        };

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | CASHBACK
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '💰 Company Cashback'
                    : '💰 Cashback',
                $formatter->formatCurrency(
                    $cashback,
                    'INR'
                )
            )
                ->description(
                    "{$cashbackBadge} • {$scopeBadge}"
                )
                ->descriptionIcon(
                    'heroicon-m-banknotes'
                )
                ->color(
                    $cashbackColor
                )
                ->icon(
                    'heroicon-o-currency-rupee'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-cashback',
                ])
                ->chart([
                    5,
                    8,
                    12,
                    10,
                    15,
                    18,
                    max($cashback, 0),
                ]),

            /*
            |--------------------------------------------------------------------------
            | SUBVENTION
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🏦 Company Subvention'
                    : '🏦 Subvention',
                $formatter->formatCurrency(
                    $subvention,
                    'INR'
                )
            )
                ->description(
                    "{$subventionBadge} • Total subvention impact"
                )
                ->descriptionIcon(
                    'heroicon-m-building-library'
                )
                ->color(
                    $subventionColor
                )
                ->icon(
                    'heroicon-o-building-library'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-subvention',
                ])
                ->chart([
                    5,
                    9,
                    14,
                    12,
                    18,
                    20,
                    max($subvention, 0),
                ]),

            /*
            |--------------------------------------------------------------------------
            | DOCKING
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '⚓ Company Docking'
                    : '⚓ Docking',
                $formatter->formatCurrency(
                    $docking,
                    'INR'
                )
            )
                ->description(
                    "{$dockingBadge} • Docking charges"
                )
                ->descriptionIcon(
                    'heroicon-m-arrow-down-circle'
                )
                ->color(
                    $dockingColor
                )
                ->icon(
                    'heroicon-o-arrow-down-circle'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-docking',
                ])
                ->chart([
                    3,
                    6,
                    5,
                    8,
                    7,
                    10,
                    max($docking, 0),
                ]),

            /*
            |--------------------------------------------------------------------------
            | EARNED INCENTIVE
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🏆 Company Incentive'
                    : '🏆 Earned Incentive',
                $formatter->formatCurrency(
                    $currentIncentive,
                    'INR'
                )
            )
                ->description(
                    "{$incentiveBadge} • {$incentiveDescription}"
                )
                ->descriptionIcon(
                    $currentIncentive > 0
                        ? 'heroicon-m-trophy'
                        : 'heroicon-m-chart-bar'
                )
                ->color(
                    $incentiveColor
                )
                ->icon(
                    'heroicon-o-trophy'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-earned',
                ])
                ->chart([
                    5,
                    12,
                    20,
                    28,
                    38,
                    50,
                    max($currentIncentive, 0),
                ]),

            /*
            |--------------------------------------------------------------------------
            | TOTAL DEDUCTIONS
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📉 Total Deductions',
                $formatter->formatCurrency(
                    $totalDeductions,
                    'INR'
                )
            )
                ->description(
                    'Cashback + Subvention + Docking'
                )
                ->descriptionIcon(
                    'heroicon-m-arrow-trending-down'
                )
                ->color(
                    $totalDeductions > 0
                        ? 'warning'
                        : 'success'
                )
                ->icon(
                    'heroicon-o-calculator'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-deductions',
                ]),

            /*
            |--------------------------------------------------------------------------
            | COUNT ACHIEVEMENT
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📊 Net Achievement',
                $formatter->formatCurrency(
                    $countAchievement,
                    'INR'
                )
            )
                ->description(
                    $target > 0
                        ? "{$achievementPercentage}% of target"
                        : 'No target assigned'
                )
                ->descriptionIcon(
                    $achievementPercentage >= 100
                        ? 'heroicon-m-check-circle'
                        : 'heroicon-m-chart-bar'
                )
                ->color(
                    $achievementPercentage >= 100
                        ? 'success'
                        : 'primary'
                )
                ->icon(
                    'heroicon-o-chart-bar'
                )
                ->extraAttributes([
                    'class' => 'performance-card incentive-card-achievement',
                ])
                ->chart([
                    10,
                    20,
                    30,
                    40,
                    55,
                    70,
                    min(
                        max(
                            $achievementPercentage,
                            0
                        ),
                        100
                    ),
                ]),
        ];
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return in_array($user->employee?->designation, [
            Employee::DESIGNATION_CALLER,
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_CLUSTER,
        ], true);
    }
}
