<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use App\Models\Employee;

class CustomerStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $todayCustomers = Customer::whereDate('created_at', $today)->count();

        $yesterdayCustomers = Customer::whereDate('created_at', $yesterday)->count();

        $totalCustomers = Customer::count();

        return [

            Stat::make('Today Customers', number_format($todayCustomers))
                ->description('Added Today')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Total Customers', number_format($totalCustomers))
                ->description('Overall Customers')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Yesterday Customers', number_format($yesterdayCustomers))
                ->description('Added Yesterday')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),

            Stat::make('This Month', Customer::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count()),

            Stat::make('This Week', Customer::whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ])->count()),

            Stat::make(
                'Pending Journey',
                Customer::where('journey_status', '!=', 'finalized')->count()
            ),

            Stat::make(
                'Completed Journey',
                Customer::where('journey_status', 'finalized')->count()
            ),

        ];
    }

    public static function canView(): bool
    {

        return auth()->check() && auth()->user()->hasRole('Admin');
    }
}
