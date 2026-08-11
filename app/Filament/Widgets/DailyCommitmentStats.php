<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class DailyCommitmentStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        /*
        |--------------------------------------------------------------------------
        | Customer Scope
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('Admin')) {

            // Admin sees ALL customers, including unassigned customers.
            $customersQuery = Customer::query();
        } else {

            if (! $employee) {
                return [
                    Stat::make(
                        'No Employee Assigned',
                        'N/A'
                    )
                        ->description('Please contact Administrator')
                        ->descriptionIcon(
                            'heroicon-m-exclamation-triangle'
                        )
                        ->color('danger'),
                ];
            }

            // Non-admin users see their hierarchy.
            $employeeIds = HierarchyHelper::subordinateIds($employee);

            $customersQuery = Customer::query()
                ->whereIn('employee_id', $employeeIds);
        }

        /*
        |--------------------------------------------------------------------------
        | Current Month
        |--------------------------------------------------------------------------
        */

        $customersQuery
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year);

        /*
        |--------------------------------------------------------------------------
        | Eligible
        |--------------------------------------------------------------------------
        */

        $eligible = (clone $customersQuery)
            ->where('eligibility_status', 'eligible')
            ->count();

        $notEligible = (clone $customersQuery)
            ->where('eligibility_status', 'not_eligible')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Total Customers
        |--------------------------------------------------------------------------
        */

        $totalCustomers = (clone $customersQuery)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Sanctioned
        |--------------------------------------------------------------------------
        */

        $sanctioned = (clone $customersQuery)
            ->whereNotNull('sanctioned_loan_amount')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Documentation Pending
        |--------------------------------------------------------------------------
        */

        $documentationPending = (clone $customersQuery)
            ->where('documentation_status', 'pending')
            ->count();
        /*
        |--------------------------------------------------------------------------
        | Description Prefix
        |--------------------------------------------------------------------------
        */

        $isAdmin = $user->hasRole('Admin');

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | Eligible OTP
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin ? '🎯 Total Eligible OTP' : '🎯 Eligible OTP',
                number_format($eligible)
            )
                ->description(
                    $isAdmin
                        ? 'All Company Eligible Loans'
                        : 'Eligible Loans in Your Hierarchy'
                )
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            /*
            |--------------------------------------------------------------------------
            | Not Eligible
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin ? '❌ Total Not Eligible' : '❌ Not Eligible',
                number_format($notEligible)
            )
                ->description(
                    $isAdmin
                        ? 'All Company Not Eligible Loans'
                        : 'Not Eligible Loans in Your Hierarchy'
                )
                ->descriptionIcon('heroicon-m-x-circle')
                ->color(
                    $notEligible > 0
                        ? 'danger'
                        : 'success'
                )
                ->icon('heroicon-o-x-circle'),

            /*
            |--------------------------------------------------------------------------
            | Total OTPs
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin ? '👥 Total Company OTPs' : '👥 No of OTPs',
                number_format($totalCustomers)
            )
                ->description(
                    $isAdmin
                        ? 'All Company Applications'
                        : 'Applications in Your Hierarchy'
                )
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->icon('heroicon-o-users'),

            /*
            |--------------------------------------------------------------------------
            | Login / Sanctioned
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin ? '🏦 Total Company Login' : '🏦 Login',
                number_format($sanctioned)
            )
                ->description(
                    $isAdmin
                        ? 'All Company Sanctioned Cases'
                        : 'Sanctioned Cases in Your Hierarchy'
                )
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning')
                ->icon('heroicon-o-banknotes'),

            /*
            |--------------------------------------------------------------------------
            | Documentation Pending
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '📄 Total Company Documentation Pending'
                    : '📄 Documentation Pending',
                number_format($documentationPending)
            )
                ->description(
                    $isAdmin
                        ? 'All Company Pending Documents'
                        : 'Pending Documents in Your Hierarchy'
                )
                ->descriptionIcon('heroicon-m-document-text')
                ->color(
                    $documentationPending > 0
                        ? 'danger'
                        : 'success'
                )
                ->icon('heroicon-o-document-text'),
        ];
    }
}
