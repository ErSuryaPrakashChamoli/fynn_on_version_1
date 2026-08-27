<?php

namespace App\Filament\Widgets;

use App\Models\CustomerJourneyDelegation;
use App\Models\CustomerSlaBreach;
use App\Models\Employee;
use App\Models\JourneyTakeover;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\JourneySlaService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers for the Customer Journey Continuity dashboard. Purely
 * a read model over the continuity tables — never mutates anything.
 */
class JourneyContinuityStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $activeBackups = CustomerJourneyDelegation::query()
            ->activeAt(now())
            ->count();

        $upcomingBackups = CustomerJourneyDelegation::query()
            ->whereIn('status', [CustomerJourneyDelegation::STATUS_PENDING, CustomerJourneyDelegation::STATUS_ACTIVE])
            ->where('start_at', '>', now())
            ->count();

        $employeesCovered = CustomerJourneyDelegation::query()
            ->activeAt(now())
            ->distinct('delegating_manager_id')
            ->count('delegating_manager_id');

        $newCustomerCoverageRules = CustomerJourneyDelegation::query()
            ->activeAt(now())
            ->where('coverage_type', '!=', 'existing')
            ->count();

        $customersDelegated = app(CustomerJourneyAccessService::class)
            ->activeDelegatedCustomerIds()
            ->count();

        $activeTakeovers = JourneyTakeover::query()
            ->where('status', JourneyTakeover::STATUS_ACTIVE)
            ->count();

        $pendingManagerCases = JourneySlaService::activeCustomersQuery()->count();

        $openSlaBreaches = CustomerSlaBreach::query()
            ->where('status', CustomerSlaBreach::STATUS_OPEN)
            ->count();

        $exitedManagerIds = Employee::query()
            ->where('designation', Employee::DESIGNATION_MANAGER)
            ->where('exit_status', 'yes')
            ->pluck('id');

        $affectedEmployeeIds = Employee::query()
            ->where(function ($query) use ($exitedManagerIds) {
                $query->whereIn('id', $exitedManagerIds)
                    ->orWhereIn('manager_id', $exitedManagerIds);
            })
            ->pluck('id');

        $pendingReassignments = JourneySlaService::activeCustomersQuery()
            ->whereIn('assign_to', $affectedEmployeeIds)
            ->count();

        $uncoveredCount = $this->employeesWithoutContinuityCoverage();

        return [
            Stat::make('Active Backup Assignments', number_format($activeBackups))
                ->icon('heroicon-o-arrows-right-left')
                ->color('info'),

            Stat::make('Upcoming Delegations', number_format($upcomingBackups))
                ->icon('heroicon-o-calendar')
                ->color('info'),

            Stat::make('Employees Currently Covered', number_format($employeesCovered))
                ->icon('heroicon-o-user-group')
                ->color('info'),

            Stat::make('Customers Currently Delegated', number_format($customersDelegated))
                ->icon('heroicon-o-users')
                ->color('info'),

            Stat::make('New Customer Coverage Rules', number_format($newCustomerCoverageRules))
                ->icon('heroicon-o-user-plus')
                ->color('info'),

            Stat::make('Emergency Takeovers', number_format($activeTakeovers))
                ->icon('heroicon-o-shield-exclamation')
                ->color($activeTakeovers > 0 ? 'danger' : 'success'),

            Stat::make('Pending Manager Cases', number_format($pendingManagerCases))
                ->icon('heroicon-o-clock')
                ->color('warning'),

            Stat::make('SLA Breaches', number_format($openSlaBreaches))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($openSlaBreaches > 0 ? 'danger' : 'success'),

            Stat::make('Pending Reassignments', number_format($pendingReassignments))
                ->icon('heroicon-o-user-minus')
                ->color($pendingReassignments > 0 ? 'danger' : 'success'),

            Stat::make('Employees Without Continuity Coverage', number_format($uncoveredCount))
                ->description($uncoveredCount > 0 ? "{$uncoveredCount} employee(s) currently have no active continuity coverage." : 'Every active Manager/Team Leader/Cluster Manager has a backup arrangement.')
                ->icon('heroicon-o-exclamation-circle')
                ->color($uncoveredCount > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Section 36's warning example: active Managers/Team Leaders/Cluster
     * Managers who currently have no active or upcoming continuity rule
     * covering them at all — i.e. no backup arrangement exists yet.
     */
    private function employeesWithoutContinuityCoverage(): int
    {
        $coveredIds = CustomerJourneyDelegation::query()
            ->whereIn('status', [CustomerJourneyDelegation::STATUS_PENDING, CustomerJourneyDelegation::STATUS_ACTIVE])
            ->where('end_at', '>=', now())
            ->pluck('delegating_manager_id')
            ->unique();

        return Employee::query()
            ->whereIn('designation', [
                Employee::DESIGNATION_MANAGER,
                Employee::DESIGNATION_TEAM_LEADER,
                Employee::DESIGNATION_CLUSTER,
            ])
            ->where('exit_status', '!=', 'yes')
            ->whereNotIn('id', $coveredIds)
            ->count();
    }
}
