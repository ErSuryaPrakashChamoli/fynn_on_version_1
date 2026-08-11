<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class DailyCommitmentStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return 'Lead Quality & Conversion Overview';
    }

    protected function getDescription(): ?string
    {
        return 'Monitor eligible leads, rejected cases, logins, and documentation status.';
    }


    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        $employee = $user?->employee;

        $isAdmin = $user->hasRole('Admin');

        /*
        |--------------------------------------------------------------------------
        | Customer Scope
        |--------------------------------------------------------------------------
        */

        if ($isAdmin) {

            // Admin sees ALL customers,
            // including customers where employee_id is NULL.
            $customersQuery = Customer::query();

            $scope = '🏢 COMPANY-WIDE';
        } else {

            if (! $employee) {
                return [
                    Stat::make(
                        'Daily Commitment',
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

            $employeeIds = HierarchyHelper::subordinateIds(
                $employee
            );

            $customersQuery = Customer::query()
                ->whereIn(
                    'employee_id',
                    $employeeIds
                );

            $scope = '👥 YOUR HIERARCHY';
        }

        /*
        |--------------------------------------------------------------------------
        | Current Month
        |--------------------------------------------------------------------------
        */

        $customersQuery
            ->whereMonth(
                'created_at',
                Carbon::now()->month
            )
            ->whereYear(
                'created_at',
                Carbon::now()->year
            );

        /*
        |--------------------------------------------------------------------------
        | Main Counts
        |--------------------------------------------------------------------------
        */

        $totalCustomers = (clone $customersQuery)
            ->count();

        $eligible = (clone $customersQuery)
            ->where(
                'eligibility_status',
                'eligible'
            )
            ->count();

        $notEligible = (clone $customersQuery)
            ->where(
                'eligibility_status',
                'not_eligible'
            )
            ->count();

        $sanctioned = (clone $customersQuery)
            ->whereNotNull(
                'sanctioned_loan_amount'
            )
            ->count();

        $documentationPending = (clone $customersQuery)
            ->where(
                'documentation_status',
                'pending'
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Today's OTP
        |--------------------------------------------------------------------------
        */

        $todayCustomers = (clone $customersQuery)
            ->whereDate(
                'created_at',
                today()
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Conversion Rates
        |--------------------------------------------------------------------------
        */

        $eligibilityRate = $totalCustomers > 0
            ? round(
                ($eligible / $totalCustomers) * 100,
                1
            )
            : 0;

        $loginRate = $eligible > 0
            ? round(
                ($sanctioned / $eligible) * 100,
                1
            )
            : 0;

        $documentationRate = $totalCustomers > 0
            ? round(
                ($documentationPending / $totalCustomers) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | 7-Day OTP Trend
        |--------------------------------------------------------------------------
        */

        $otpTrend = [];

        for ($i = 6; $i >= 0; $i--) {

            $date = now()->subDays($i);

            $otpTrend[] = (clone $customersQuery)
                ->whereDate(
                    'created_at',
                    $date
                )
                ->count();
        }

        /*
        |--------------------------------------------------------------------------
        | Eligibility Trend
        |--------------------------------------------------------------------------
        */

        $eligibleTrend = [];

        for ($i = 6; $i >= 0; $i--) {

            $date = now()->subDays($i);

            $eligibleTrend[] = (clone $customersQuery)
                ->whereDate(
                    'created_at',
                    $date
                )
                ->where(
                    'eligibility_status',
                    'eligible'
                )
                ->count();
        }

        /*
        |--------------------------------------------------------------------------
        | Eligible Status
        |--------------------------------------------------------------------------
        */

        if ($eligibilityRate >= 90) {

            $eligibleBadge = '🏆 EXCELLENT';
            $eligibleColor = 'success';
            $eligibleDescription = "{$eligibilityRate}% eligibility rate";
        } elseif ($eligibilityRate >= 70) {

            $eligibleBadge = '🔥 HEALTHY';
            $eligibleColor = 'success';
            $eligibleDescription = "{$eligibilityRate}% eligibility rate";
        } elseif ($eligibilityRate >= 50) {

            $eligibleBadge = '⚡ MODERATE';
            $eligibleColor = 'warning';
            $eligibleDescription = "{$eligibilityRate}% eligibility rate";
        } else {

            $eligibleBadge = '⚠️ NEEDS ATTENTION';
            $eligibleColor = 'danger';
            $eligibleDescription = "{$eligibilityRate}% eligibility rate";
        }

        /*
        |--------------------------------------------------------------------------
        | Not Eligible Status
        |--------------------------------------------------------------------------
        */

        if ($notEligible === 0) {

            $notEligibleBadge = '✅ ZERO REJECTION';
            $notEligibleColor = 'success';
        } elseif ($notEligible <= 5) {

            $notEligibleBadge = '🟢 LOW';
            $notEligibleColor = 'success';
        } elseif ($notEligible <= 10) {

            $notEligibleBadge = '🟡 MODERATE';
            $notEligibleColor = 'warning';
        } else {

            $notEligibleBadge = '🔴 HIGH';
            $notEligibleColor = 'danger';
        }

        /*
        |--------------------------------------------------------------------------
        | Login Status
        |--------------------------------------------------------------------------
        */

        if ($loginRate >= 50) {

            $loginBadge = '🏆 EXCELLENT';
            $loginColor = 'success';
        } elseif ($loginRate >= 30) {

            $loginBadge = '⚡ HEALTHY';
            $loginColor = 'warning';
        } elseif ($loginRate > 0) {

            $loginBadge = '📈 IN PROGRESS';
            $loginColor = 'primary';
        } else {

            $loginBadge = '⚠️ NO LOGIN';
            $loginColor = 'danger';
        }

        /*
        |--------------------------------------------------------------------------
        | Documentation Status
        |--------------------------------------------------------------------------
        */

        if ($documentationPending === 0) {

            $documentationBadge = '✅ ALL CLEAR';
            $documentationColor = 'success';
            $documentationIcon = 'heroicon-m-check-circle';
        } elseif ($documentationRate <= 20) {

            $documentationBadge = '🟢 LOW PENDING';
            $documentationColor = 'success';
            $documentationIcon = 'heroicon-m-clock';
        } elseif ($documentationRate <= 40) {

            $documentationBadge = '🟡 NEEDS ATTENTION';
            $documentationColor = 'warning';
            $documentationIcon = 'heroicon-m-clock';
        } else {

            $documentationBadge = '🔴 HIGH PENDING';
            $documentationColor = 'danger';
            $documentationIcon = 'heroicon-m-exclamation-triangle';
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | TOTAL OTP
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '👥 Company OTPs'
                    : '👥 No. of OTPs',
                number_format(
                    $totalCustomers
                )
            )
                ->description(
                    "{$scope} • {$todayCustomers} added today"
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
                    'performance-card commitment-card-total',
                ])
                ->chart(
                    $otpTrend
                ),

            /*
            |--------------------------------------------------------------------------
            | ELIGIBLE
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🎯 Company Eligible'
                    : '🎯 Eligible OTP',
                number_format(
                    $eligible
                )
            )
                ->description(
                    "{$eligibleBadge} • {$eligibleDescription}"
                )
                ->descriptionIcon(
                    'heroicon-m-check-circle'
                )
                ->color(
                    $eligibleColor
                )
                ->icon(
                    'heroicon-o-check-circle'
                )
                ->extraAttributes([
                    'class' =>
                    'performance-card commitment-card-eligible',
                ])
                ->chart(
                    $eligibleTrend
                ),

            /*
            |--------------------------------------------------------------------------
            | NOT ELIGIBLE
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '❌ Company Not Eligible'
                    : '❌ Not Eligible',
                number_format(
                    $notEligible
                )
            )
                ->description(
                    "{$notEligibleBadge} • {$notEligible} cases"
                )
                ->descriptionIcon(
                    $notEligible > 0
                        ? 'heroicon-m-x-circle'
                        : 'heroicon-m-check-circle'
                )
                ->color(
                    $notEligibleColor
                )
                ->icon(
                    'heroicon-o-x-circle'
                )
                ->extraAttributes([
                    'class' =>
                    'performance-card commitment-card-not-eligible',
                ]),

            /*
            |--------------------------------------------------------------------------
            | LOGIN
            |--------------------------------------------------------------------------
            */

            Stat::make(
                $isAdmin
                    ? '🏦 Company Login'
                    : '🏦 Login',
                number_format(
                    $sanctioned
                )
            )
                ->description(
                    "{$loginBadge} • {$loginRate}% from eligible"
                )
                ->descriptionIcon(
                    'heroicon-m-building-library'
                )
                ->color(
                    $loginColor
                )
                ->icon(
                    'heroicon-o-building-library'
                )
                ->extraAttributes([
                    'class' =>
                    'performance-card commitment-card-login',
                ]),

            /*
            |--------------------------------------------------------------------------
            | DOCUMENTATION
            |--------------------------------------------------------------------------
            */

            Stat::make(
                '📄 Documentation Pending',
                number_format(
                    $documentationPending
                )
            )
                ->description(
                    "{$documentationBadge} • {$documentationRate}% of applications"
                )
                ->descriptionIcon(
                    $documentationIcon
                )
                ->color(
                    $documentationColor
                )
                ->icon(
                    'heroicon-o-document-text'
                )
                ->extraAttributes([
                    'class' =>
                    'performance-card commitment-card-documentation',
                ]),
        ];
    }
}
