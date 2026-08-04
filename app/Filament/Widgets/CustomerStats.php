<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use App\Services\HierarchyService;

class CustomerStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $employeeIds = HierarchyService::visibleEmployeeIds(Auth::user());

        $query = Customer::whereIn('employee_id', $employeeIds);

        return [

            Stat::make(
                'Today Customers',
                number_format(
                    (clone $query)->whereDate('created_at', $today)->count()
                )
            )
                ->description('Added Today')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make(
                'Total Customers',
                number_format(
                    (clone $query)->count()
                )
            )
                ->description('Overall Customers')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make(
                'Yesterday Customers',
                number_format(
                    (clone $query)->whereDate('created_at', $yesterday)->count()
                )
            )
                ->description('Added Yesterday')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),

            Stat::make(
                'This Month',
                (clone $query)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count()
            ),

            Stat::make(
                'This Week',
                (clone $query)
                    ->whereBetween('created_at', [
                        now()->startOfWeek(),
                        now()->endOfWeek(),
                    ])
                    ->count()
            ),

            Stat::make(
                'Pending Journey',
                (clone $query)
                    ->where('journey_status', '!=', 'finalized')
                    ->count()
            ),

            Stat::make(
                'Completed Journey',
                (clone $query)
                    ->where('journey_status', 'finalized')
                    ->count()
            ),

        ];
    }

    // public static function canView(): bool
    // {

    //     return auth()->check() && auth()->user()->hasRole('Admin');
    // }
}
