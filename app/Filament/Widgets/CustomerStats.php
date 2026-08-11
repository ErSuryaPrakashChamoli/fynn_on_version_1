<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class CustomerStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected ?string $pollingInterval = '60s';


    protected function getHeading(): ?string
    {
        return 'Customer Acquisition & Journey';
    }

    protected function getDescription(): ?string
    {
         return 'Monitor customer acquisition, journey progress, and completion across your hierarchy.';
    }

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
                    'Customer Statistics',
                    'N/A'
                )
                    ->description(
                        'Employee profile is not assigned'
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
        | Customer Scope
        |--------------------------------------------------------------------------
        */

        if ($isAdmin) {

            // Admin sees absolutely ALL customers,
            // including unassigned customers.
            $query = Customer::query();

            $scopeBadge = '🏢 Overall Portfolio';

        } else {

            $employeeIds = HierarchyHelper::subordinateIds(
                $employee
            );

            $query = Customer::query()
                ->whereIn(
                    'employee_id',
                    $employeeIds
                );

            $scopeBadge = '👥 Your Portfolio';
        }

        /*
        |--------------------------------------------------------------------------
        | Dates
        |--------------------------------------------------------------------------
        */

        $today = Carbon::today();

        $yesterday = Carbon::yesterday();

        /*
        |--------------------------------------------------------------------------
        | Main Statistics
        |--------------------------------------------------------------------------
        */

        $todayCustomers = (clone $query)
            ->whereDate(
                'created_at',
                $today
            )
            ->count();

        $yesterdayCustomers = (clone $query)
            ->whereDate(
                'created_at',
                $yesterday
            )
            ->count();

        $totalCustomers = (clone $query)
            ->count();

        $thisMonth = (clone $query)
            ->whereMonth(
                'created_at',
                now()->month
            )
            ->whereYear(
                'created_at',
                now()->year
            )
            ->count();

        $thisWeek = (clone $query)
            ->whereBetween(
                'created_at',
                [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ]
            )
            ->count();

        $pendingJourney = (clone $query)
            ->where(
                'journey_status',
                '!=',
                'finalized'
            )
            ->count();

        $completedJourney = (clone $query)
            ->where(
                'journey_status',
                'finalized'
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Journey Completion %
        |--------------------------------------------------------------------------
        */

        $completionRate = $totalCustomers > 0
            ? round(
                ($completedJourney / $totalCustomers) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Pending %
        |--------------------------------------------------------------------------
        */

        $pendingRate = $totalCustomers > 0
            ? round(
                ($pendingJourney / $totalCustomers) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Daily Growth
        |--------------------------------------------------------------------------
        */

        if ($yesterdayCustomers > 0) {

            $dailyGrowth = round(
                (
                    ($todayCustomers - $yesterdayCustomers)
                    / $yesterdayCustomers
                ) * 100,
                1
            );

        } else {

            $dailyGrowth = $todayCustomers > 0
                ? 100
                : 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Monthly Average
        |--------------------------------------------------------------------------
        */

        $daysPassed = max(
            now()->day,
            1
        );

        $monthlyAverage = round(
            $thisMonth / $daysPassed,
            1
        );

        /*
        |--------------------------------------------------------------------------
        | Today Status
        |--------------------------------------------------------------------------
        */

        if ($todayCustomers > $yesterdayCustomers) {

            $todayBadge = '🔥 ABOVE YESTERDAY';

            $todayDescription = "+{$dailyGrowth}% customer growth";

            $todayColor = 'success';

            $todayIcon = 'heroicon-m-arrow-trending-up';

        } elseif ($todayCustomers === $yesterdayCustomers) {

            $todayBadge = '➡️ STABLE';

            $todayDescription = 'Same as yesterday';

            $todayColor = 'warning';

            $todayIcon = 'heroicon-m-minus';

        } else {

            $todayBadge = '📉 BELOW YESTERDAY';

            $todayDescription = "{$dailyGrowth}% vs yesterday";

            $todayColor = 'danger';

            $todayIcon = 'heroicon-m-arrow-trending-down';
        }

        /*
        |--------------------------------------------------------------------------
        | Journey Status
        |--------------------------------------------------------------------------
        */

        if ($completionRate >= 75) {

            $journeyBadge = '🏆 EXCELLENT';

            $journeyColor = 'success';

        } elseif ($completionRate >= 50) {

            $journeyBadge = '⚡ HEALTHY';

            $journeyColor = 'warning';

        } elseif ($completionRate > 0) {

            $journeyBadge = '📈 IN PROGRESS';

            $journeyColor = 'primary';

        } else {

            $journeyBadge = '⚠️ NOT STARTED';

            $journeyColor = 'danger';
        }

        /*
        |--------------------------------------------------------------------------
        | Pending Status
        |--------------------------------------------------------------------------
        */

        if ($pendingJourney === 0) {

            $pendingBadge = '✅ ALL CLEAR';

            $pendingColor = 'success';

            $pendingIcon = 'heroicon-m-check-circle';

        } elseif ($pendingRate <= 25) {

            $pendingBadge = '🟢 LOW PENDING';

            $pendingColor = 'success';

            $pendingIcon = 'heroicon-m-check-circle';

        } elseif ($pendingRate <= 50) {

            $pendingBadge = '🟡 NEEDS ATTENTION';

            $pendingColor = 'warning';

            $pendingIcon = 'heroicon-m-clock';

        } else {

            $pendingBadge = '🔴 HIGH PENDING';

            $pendingColor = 'danger';

            $pendingIcon = 'heroicon-m-exclamation-triangle';
        }

        /*
        |--------------------------------------------------------------------------
        | 7 Day Customer Trend
        |--------------------------------------------------------------------------
        */

        $trend = [];

        for ($i = 6; $i >= 0; $i--) {

            $date = now()->subDays($i);

            $trend[] = (clone $query)
                ->whereDate(
                    'created_at',
                    $date
                )
                ->count();
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | TODAY
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📅 Today Customers',
                number_format(
                    $todayCustomers
                )
            )
                ->description(
                    "{$scopeBadge} • {$todayBadge}"
                )
                ->descriptionIcon(
                    $todayIcon
                )
                ->color(
                    $todayColor
                )
                ->icon(
                    'heroicon-o-user-plus'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-today',
                ])
                ->chart(
                    $trend
                ),

            /*
            |--------------------------------------------------------------------------
            | TOTAL
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🏢 Company Customers'
                    : '👥 Total Customers',
                number_format(
                    $totalCustomers
                )
            )
                ->description(
                    $isAdmin
                        ? '🏢 Complete company customer base'
                        : '👥 Customers within your hierarchy'
                )
                ->descriptionIcon(
                    'heroicon-m-users'
                )
                ->color('primary')
                ->icon(
                    'heroicon-o-users'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-total',
                ])
                ->chart([
                    max($thisMonth - 10, 0),
                    max($thisMonth - 7, 0),
                    max($thisMonth - 5, 0),
                    max($thisMonth - 3, 0),
                    max($thisMonth - 2, 0),
                    $thisMonth,
                    $totalCustomers,
                ]),

            /*
            |--------------------------------------------------------------------------
            | YESTERDAY
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '🗓️ Yesterday',
                number_format(
                    $yesterdayCustomers
                )
            )
                ->description(
                    'Previous day customer intake'
                )
                ->descriptionIcon(
                    'heroicon-m-calendar-days'
                )
                ->color('warning')
                ->icon(
                    'heroicon-o-calendar-days'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-yesterday',
                ]),

            /*
            |--------------------------------------------------------------------------
            | THIS MONTH
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📊 This Month',
                number_format(
                    $thisMonth
                )
            )
                ->description(
                    "{$monthlyAverage} applications/day average"
                )
                ->descriptionIcon(
                    'heroicon-m-chart-bar'
                )
                ->color('info')
                ->icon(
                    'heroicon-o-chart-bar'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-month',
                ])
                ->chart([
                    max($thisMonth - 12, 0),
                    max($thisMonth - 9, 0),
                    max($thisMonth - 6, 0),
                    max($thisMonth - 4, 0),
                    max($thisMonth - 2, 0),
                    $thisMonth,
                ]),

            /*
            |--------------------------------------------------------------------------
            | THIS WEEK
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📈 This Week',
                number_format(
                    $thisWeek
                )
            )
                ->description(
                    $thisWeek > 0
                        ? 'Active weekly acquisition'
                        : 'No customers this week'
                )
                ->descriptionIcon(
                    'heroicon-m-arrow-trending-up'
                )
                ->color(
                    $thisWeek > 0
                        ? 'success'
                        : 'danger'
                )
                ->icon(
                    'heroicon-o-calendar'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-week',
                ]),

            /*
            |--------------------------------------------------------------------------
            | PENDING JOURNEY
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '⏳ Pending Journey',
                number_format(
                    $pendingJourney
                )
            )
                ->description(
                    "{$pendingBadge} • {$pendingRate}% of customers"
                )
                ->descriptionIcon(
                    $pendingIcon
                )
                ->color(
                    $pendingColor
                )
                ->icon(
                    'heroicon-o-clock'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-pending',
                ]),

            /*
            |--------------------------------------------------------------------------
            | COMPLETED
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '✅ Completed Journey',
                number_format(
                    $completedJourney
                )
            )
                ->description(
                    "{$journeyBadge} • {$completionRate}% completion"
                )
                ->descriptionIcon(
                    'heroicon-m-check-circle'
                )
                ->color(
                    $journeyColor
                )
                ->icon(
                    'heroicon-o-check-circle'
                )
                ->extraAttributes([
                    'class' =>
                        'performance-card customer-card-completed',
                ])
                ->chart([
                    10,
                    18,
                    25,
                    32,
                    42,
                    55,
                    min(
                        $completionRate,
                        100
                    ),
                ]),
        ];
    }
}
