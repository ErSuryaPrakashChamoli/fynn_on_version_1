<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use NumberFormatter;

class PerformanceStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        $isAdmin = $user->hasRole('Admin');

        /*
        |--------------------------------------------------------------------------
        | Employee Validation
        |--------------------------------------------------------------------------
        */

        if (! $isAdmin && ! $employee) {
            return [
                Stat::make(
                    'Performance',
                    'Employee Not Found'
                )
                    ->description(
                        'Employee profile is not linked with this user'
                    )
                    ->descriptionIcon(
                        'heroicon-m-exclamation-triangle'
                    )
                    ->color('danger')
                    ->icon(
                        'heroicon-o-exclamation-triangle'
                    ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Achievement Calculator
        |--------------------------------------------------------------------------
        */

        $calculator = app(
            AchievementCalculatorService::class
        );

        $performance = $calculator->getPerformance(
            $employee
        );

        /*
        |--------------------------------------------------------------------------
        | Performance Values
        |--------------------------------------------------------------------------
        */

        $target = (float) (
            $performance['target'] ?? 0
        );

        $achievement = (float) (
            $performance['actual'] ?? 0
        );

        $countAchievement = (float) (
            $performance['count_achievement'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Pending
        |--------------------------------------------------------------------------
        */

        $pending = max(
            $target - $countAchievement,
            0
        );

        /*
        |--------------------------------------------------------------------------
        | Remaining Days
        |--------------------------------------------------------------------------
        */

        $remainingDays = now()->daysInMonth
            - now()->day
            + 1;

        /*
        |--------------------------------------------------------------------------
        | DRR
        |--------------------------------------------------------------------------
        */

        $drr = $pending > 0 && $remainingDays > 0
            ? $pending / $remainingDays
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Approved Amount
        |--------------------------------------------------------------------------
        */

        if ($isAdmin) {

            $approvedAmount = (float) Customer::query()
                ->whereMonth(
                    'created_at',
                    now()->month
                )
                ->whereYear(
                    'created_at',
                    now()->year
                )
                ->sum(
                    'approved_loan_amount'
                );
        } else {

            $employeeIds = HierarchyHelper::subordinateIds(
                $employee
            );

            $approvedAmount = (float) Customer::query()
                ->whereIn(
                    'employee_id',
                    $employeeIds
                )
                ->whereMonth(
                    'created_at',
                    now()->month
                )
                ->whereYear(
                    'created_at',
                    now()->year
                )
                ->sum(
                    'approved_loan_amount'
                );
        }

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
        | Progress
        |--------------------------------------------------------------------------
        */

        $progress = min(
            max($achievementPercentage, 0),
            100
        );

        /*
        |--------------------------------------------------------------------------
        | Target Status
        |--------------------------------------------------------------------------
        */

        if ($achievementPercentage >= 100) {

            $targetBadge = '🏆 TARGET ACHIEVED';

            $targetDescription = 'Excellent! Monthly target completed';

            $targetColor = 'success';

            $targetIcon = 'heroicon-m-trophy';
        } elseif ($achievementPercentage >= 90) {

            $targetBadge = '🔥 ALMOST THERE';

            $targetDescription = "{$achievementPercentage}% achieved • Final push";

            $targetColor = 'success';

            $targetIcon = 'heroicon-m-fire';
        } elseif ($achievementPercentage >= 75) {

            $targetBadge = '⚡ ON TRACK';

            $targetDescription = "{$achievementPercentage}% achieved • Keep pushing";

            $targetColor = 'warning';

            $targetIcon = 'heroicon-m-bolt';
        } elseif ($achievementPercentage >= 50) {

            $targetBadge = '📈 IN PROGRESS';

            $targetDescription = "{$achievementPercentage}% achieved";

            $targetColor = 'warning';

            $targetIcon = 'heroicon-m-chart-bar';
        } elseif ($achievementPercentage > 0) {

            $targetBadge = '🚀 NEEDS PUSH';

            $targetDescription = "{$achievementPercentage}% achieved";

            $targetColor = 'primary';

            $targetIcon = 'heroicon-m-arrow-trending-up';
        } else {

            $targetBadge = '⚠️ NOT STARTED';

            $targetDescription = 'No achievement recorded yet';

            $targetColor = 'danger';

            $targetIcon = 'heroicon-m-exclamation-triangle';
        }

        /*
        |--------------------------------------------------------------------------
        | DRR Status
        |--------------------------------------------------------------------------
        */

        if ($pending <= 0) {

            $drrBadge = '✅ COMPLETE';

            $drrDescription = 'No daily requirement';

            $drrColor = 'success';

            $drrIcon = 'heroicon-m-check-circle';
        } elseif ($remainingDays <= 5) {

            $drrBadge = '🚨 CRITICAL';

            $drrDescription = "{$remainingDays} days remaining";

            $drrColor = 'danger';

            $drrIcon = 'heroicon-m-exclamation-triangle';
        } elseif ($remainingDays <= 10) {

            $drrBadge = '⚠️ URGENT';

            $drrDescription = "{$remainingDays} days remaining";

            $drrColor = 'warning';

            $drrIcon = 'heroicon-m-clock';
        } else {

            $drrBadge = '📅 DAILY PLAN';

            $drrDescription = "{$remainingDays} days remaining";

            $drrColor = 'primary';

            $drrIcon = 'heroicon-m-calendar-days';
        }

        /*
        |--------------------------------------------------------------------------
        | Scope Badge
        |--------------------------------------------------------------------------
        */

        $scopeBadge = $isAdmin
            ? '🏢 COMPANY-WIDE'
            : '👥 YOUR HIERARCHY';

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
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | TARGET
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🎯 Company Target'
                    : '🎯 Monthly Target',
                $formatter->formatCurrency(
                    $target,
                    'INR'
                )
            )
                ->description(
                    "{$scopeBadge} • {$targetBadge}"
                )
                ->descriptionIcon($targetIcon)
                ->color($targetColor)
                ->icon('heroicon-o-flag')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-target',
                ])
                ->chart([
                    10,
                    18,
                    28,
                    40,
                    52,
                    65,
                    $progress,
                ]),
            /*
            |--------------------------------------------------------------------------
            | ACTUAL
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '💰 Actual Achievement',
                $formatter->formatCurrency(
                    $achievement,
                    'INR'
                )
            )
                ->description(
                    $isAdmin
                        ? '🏢 Company disbursed volume'
                        : '💼 Disbursed loan volume'
                )
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->icon('heroicon-o-banknotes')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-actual',
                ])
                ->chart([
                    15,
                    25,
                    32,
                    42,
                    55,
                    68,
                    min($progress, 100),
                ]),
            /*
            |--------------------------------------------------------------------------
            | COUNT ACHIEVEMENT
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📊 Count Achievement',
                $formatter->formatCurrency(
                    $countAchievement,
                    'INR'
                )
            )
                ->description(
                    "🎯 {$achievementPercentage}% of target achieved"
                )
                ->descriptionIcon(
                    $achievementPercentage >= 100
                        ? 'heroicon-m-check-circle'
                        : 'heroicon-m-chart-bar'
                )
                ->color(
                    $achievementPercentage >= 100
                        ? 'success'
                        : $targetColor
                )
                ->icon('heroicon-o-chart-bar')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-count',
                ])
                ->chart([
                    8,
                    20,
                    30,
                    38,
                    50,
                    62,
                    $progress,
                ]),

            /*
            |--------------------------------------------------------------------------
            | PENDING
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '⏳ Pending Target',
                $formatter->formatCurrency(
                    $pending,
                    'INR'
                )
            )
                ->description(
                    $pending > 0
                        ? "🔻 {$this->formatCompact($pending)} still required"
                        : '🎉 Target completely achieved'
                )
                ->descriptionIcon(
                    $pending > 0
                        ? 'heroicon-m-clock'
                        : 'heroicon-m-check-circle'
                )
                ->color(
                    $pending > 0
                        ? 'warning'
                        : 'success'
                )
                ->icon('heroicon-o-clock')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-pending',
                ]),

            /*
            |--------------------------------------------------------------------------
            | DRR
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📈 Daily Required Rate',
                $formatter->formatCurrency(
                    $drr,
                    'INR'
                )
            )
                ->description(
                    "{$drrBadge} • {$drrDescription}"
                )
                ->descriptionIcon($drrIcon)
                ->color($drrColor)
                ->icon('heroicon-o-arrow-trending-up')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-drr',
                ]),

            /*
            |--------------------------------------------------------------------------
            | APPROVED
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '✅ Company Approved'
                    : '✅ Approved Amount',
                $formatter->formatCurrency(
                    $approvedAmount,
                    'INR'
                )
            )
                ->description(
                    $isAdmin
                        ? '🏢 Total company approved volume'
                        : '💼 Total approved loan amount'
                )
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->extraAttributes([
                    'class' => 'performance-card performance-card-approved',
                ]),
        ];
    }

    /**
     * Compact Indian currency format.
     */
    private function formatCompact(
        float $amount
    ): string {

        if ($amount >= 10000000) {
            return number_format(
                $amount / 10000000,
                2
            ) . ' Cr';
        }

        if ($amount >= 100000) {
            return number_format(
                $amount / 100000,
                2
            ) . ' L';
        }

        if ($amount >= 1000) {
            return number_format(
                $amount / 1000,
                2
            ) . ' K';
        }

        return number_format(
            $amount,
            0
        );
    }
}
