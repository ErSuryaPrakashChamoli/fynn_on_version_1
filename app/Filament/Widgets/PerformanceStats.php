<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;

class PerformanceStats extends BaseWidget
{
    // protected static ?int $sort = 2;
      protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | Get logged-in employee
        |--------------------------------------------------------------------------
        */

        $employee = $user?->employee;

        if (! $employee) {
            return [
                Stat::make('Performance', 'Employee Not Found')
                    ->description('Employee profile is not linked with this user')
                    ->color('danger'),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculator Service
        |--------------------------------------------------------------------------
        */

        $calculator = app(AchievementCalculatorService::class);

        /*
        |--------------------------------------------------------------------------
        | Performance Data
        |--------------------------------------------------------------------------
        */

        $performance = $calculator->getPerformance($employee);

        $target = (float) ($performance['target'] ?? 0);

        $achievement = (float) ($performance['actual'] ?? 0);

        $countAchievement = (float) (
            $performance['count_achievement'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Pending Target
        |--------------------------------------------------------------------------
        */

        $pending = max($target - $countAchievement, 0);

        /*
        |--------------------------------------------------------------------------
        | Daily Required Rate
        |--------------------------------------------------------------------------
        */

        $remainingDays = now()->daysInMonth - now()->day + 1;

        $drr = $pending > 0 && $remainingDays > 0
            ? $pending / $remainingDays
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Approved Amount
        |--------------------------------------------------------------------------
        */

        $employeeIds = HierarchyHelper::subordinateIds($employee);

        $approvedAmount = (float) Customer::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('approved_loan_amount');

        /*
        |--------------------------------------------------------------------------
        | Target Achievement %
        |--------------------------------------------------------------------------
        */

        $achievementPercentage = $target > 0
            ? round(($countAchievement / $target) * 100, 1)
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Target Status
        |--------------------------------------------------------------------------
        */

        if ($achievementPercentage >= 100) {
            $targetLevel = '🎉 Target Achieved';
            $targetColor = 'success';
        } elseif ($achievementPercentage >= 75) {
            $targetLevel = "{$achievementPercentage}% achieved";
            $targetColor = 'warning';
        } elseif ($achievementPercentage > 0) {
            $targetLevel = "{$achievementPercentage}% achieved";
            $targetColor = 'primary';
        } else {
            $targetLevel = 'No achievement yet';
            $targetColor = 'danger';
        }

        /*
        |--------------------------------------------------------------------------
        | Currency Formatter
        |--------------------------------------------------------------------------
        */

        $formatter = new NumberFormatter(
            'en_IN',
            NumberFormatter::CURRENCY
        );

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            Stat::make(
                '🎯 Target second',
                $formatter->formatCurrency($target, 'INR')
            )
                ->description($targetLevel)
                ->descriptionIcon('heroicon-m-flag')
                ->color($targetColor),

            Stat::make(
                '💰 Actual Achievement',
                $formatter->formatCurrency($achievement, 'INR')
            )
                ->description('Disbursed Loan Volume')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make(
                '📊 Count Achievement',
                $formatter->formatCurrency($countAchievement, 'INR')
            )
                ->description('Adjusted Net Volume')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),

            Stat::make(
                '⏳ Pending Target',
                $formatter->formatCurrency($pending, 'INR')
            )
                ->description(
                    $pending > 0
                        ? "₹" . $this->formatCompact($pending) . ' remaining'
                        : 'Target completed'
                )
                ->descriptionIcon(
                    $pending > 0
                        ? 'heroicon-m-clock'
                        : 'heroicon-m-check-circle'
                )
                ->color($pending > 0 ? 'warning' : 'success'),

            Stat::make(
                '📈 DRR',
                $formatter->formatCurrency($drr, 'INR')
            )
                ->description(
                    $pending > 0
                        ? "{$remainingDays} days remaining"
                        : 'No daily requirement'
                )
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($drr > 0 ? 'danger' : 'success'),

            Stat::make(
                '✅ Approved Amount',
                $formatter->formatCurrency($approvedAmount, 'INR')
            )
                ->description('Total Approved Loan Amount')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
        ];
    }

    /**
     * Format large values for descriptions.
     */
    private function formatCompact(float $amount): string
    {
        if ($amount >= 10000000) {
            return number_format($amount / 10000000, 2) . ' Cr';
        }

        if ($amount >= 100000) {
            return number_format($amount / 100000, 2) . ' L';
        }

        if ($amount >= 1000) {
            return number_format($amount / 1000, 2) . ' K';
        }

        return number_format($amount, 0);
    }
}
