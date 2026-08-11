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
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        /*
        |--------------------------------------------------------------------------
        | Employee
        |--------------------------------------------------------------------------
        */

        $employee = $user?->employee;

        $isAdmin = $user->hasRole('Admin');

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
                    ->color('danger'),
            ];
        }
        /*
        |--------------------------------------------------------------------------
        | Employee Scope
        |--------------------------------------------------------------------------
        |
        | Admin    -> All employees
        | Cluster  -> Cluster + Managers + TLs + Callers
        | Manager  -> Manager + TLs + Callers
        | TL       -> TL + Callers
        | Caller   -> Caller
        |
        */

        // if ($user->hasRole('Admin')) {

        //     $employeeIds = \App\Models\Employee::pluck('id');
        // } else {

        //     $employeeIds = HierarchyHelper::subordinateIds(
        //         $employee
        //     );
        // }

        /*
        |--------------------------------------------------------------------------
        | Customer Query
        |--------------------------------------------------------------------------
        */

        // $query = Customer::query()
        //     ->whereIn(
        //         'employee_id',
        //         $employeeIds
        //     );


        /*
        |--------------------------------------------------------------------------
        | Customer Scope
        |--------------------------------------------------------------------------
        */

        if ($isAdmin) {

            // Admin sees absolutely ALL customers,
            // including unassigned customers.
            $query = Customer::query();
        } else {

            $employeeIds = HierarchyHelper::subordinateIds(
                $employee
            );

            $query = Customer::query()
                ->whereIn(
                    'employee_id',
                    $employeeIds
                );
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
        | Statistics
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | Today
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📅 Today Customers',
                number_format(
                    (clone $query)
                        ->whereDate(
                            'created_at',
                            $today
                        )
                        ->count()
                )
            )
                ->description('Customers Added Today')
                ->descriptionIcon(
                    'heroicon-m-arrow-trending-up'
                )
                ->color('success')
                ->icon(
                    'heroicon-o-user-plus'
                ),

            /*
            |--------------------------------------------------------------------------
            | Total
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '👥 Total Customers',
                number_format(
                    (clone $query)->count()
                )
            )
                ->description(
                    $user->hasRole('Admin')
                        ? 'Company Total Customers'
                        : 'Total Team Customers'
                )
                ->descriptionIcon(
                    'heroicon-m-users'
                )
                ->color('primary')
                ->icon(
                    'heroicon-o-users'
                ),

            /*
            |--------------------------------------------------------------------------
            | Yesterday
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '🗓️ Yesterday Customers',
                number_format(
                    (clone $query)
                        ->whereDate(
                            'created_at',
                            $yesterday
                        )
                        ->count()
                )
            )
                ->description('Customers Added Yesterday')
                ->descriptionIcon(
                    'heroicon-m-calendar-days'
                )
                ->color('warning')
                ->icon(
                    'heroicon-o-calendar-days'
                ),

            /*
            |--------------------------------------------------------------------------
            | This Month
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📊 This Month',
                number_format(
                    (clone $query)
                        ->whereMonth(
                            'created_at',
                            now()->month
                        )
                        ->whereYear(
                            'created_at',
                            now()->year
                        )
                        ->count()
                )
            )
                ->description('Customers Added This Month')
                ->descriptionIcon(
                    'heroicon-m-chart-bar'
                )
                ->color('info')
                ->icon(
                    'heroicon-o-chart-bar'
                ),

            /*
            |--------------------------------------------------------------------------
            | This Week
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📈 This Week',
                number_format(
                    (clone $query)
                        ->whereBetween(
                            'created_at',
                            [
                                now()->startOfWeek(),
                                now()->endOfWeek(),
                            ]
                        )
                        ->count()
                )
            )
                ->description('Customers Added This Week')
                ->descriptionIcon(
                    'heroicon-m-arrow-trending-up'
                )
                ->color('success')
                ->icon(
                    'heroicon-o-calendar'
                ),

            /*
            |--------------------------------------------------------------------------
            | Pending Journey
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '⏳ Pending Journey',
                number_format(
                    (clone $query)
                        ->where(
                            'journey_status',
                            '!=',
                            'finalized'
                        )
                        ->count()
                )
            )
                ->description('Customer Journeys In Progress')
                ->descriptionIcon(
                    'heroicon-m-clock'
                )
                ->color('warning')
                ->icon(
                    'heroicon-o-clock'
                ),

            /*
            |--------------------------------------------------------------------------
            | Completed Journey
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '✅ Completed Journey',
                number_format(
                    (clone $query)
                        ->where(
                            'journey_status',
                            'finalized'
                        )
                        ->count()
                )
            )
                ->description('Completed Customer Journeys')
                ->descriptionIcon(
                    'heroicon-m-check-circle'
                )
                ->color('success')
                ->icon(
                    'heroicon-o-check-circle'
                ),
        ];
    }
}
