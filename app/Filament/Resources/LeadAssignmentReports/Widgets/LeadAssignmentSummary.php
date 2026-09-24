<?php

namespace App\Filament\Resources\LeadAssignmentReports\Widgets;

use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentTransfer;
use App\Models\User;
use App\Services\HierarchyService;
use App\Support\LeadAssignmentFilters;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Reactive;

/**
 * Totals across every lead the report's filters select, shown above the
 * per-employee table so the admin sees the overall picture first.
 */
class LeadAssignmentSummary extends StatsOverviewWidget
{
    /**
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $tableFilters = null;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Leads in scope, limited to the viewer's branch and the User / Role filters.
     */
    public function assignmentsQuery(): Builder
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return CustomerAssignment::query()->whereRaw('1 = 0');
        }

        $employeeIds = array_filter((array) data_get($this->tableFilters, 'id.values', []));
        $designations = array_filter((array) data_get($this->tableFilters, 'designation.values', []));

        return LeadAssignmentFilters::scopeAssignments(CustomerAssignment::query(), $this->tableFilters)
            ->when(! $user->hasRole('Admin'), fn (Builder $query) => $query->whereIn('customer_assignments.employee_id', HierarchyService::visibleEmployeeIds($user)))
            ->when($employeeIds, fn (Builder $query) => $query->whereIn('customer_assignments.employee_id', $employeeIds))
            ->when($designations, fn (Builder $query) => $query->whereHas('employee', fn (Builder $query) => $query->whereIn('designation', $designations)));
    }

    protected function getStats(): array
    {
        $total = (clone $this->assignmentsQuery())->count();
        $percent = fn (int $count): string => $total ? round($count / $total * 100).'% of assigned' : '—';

        $untouched = $this->assignmentsQuery()->untouched()->count();
        $opened = $this->assignmentsQuery()->where('customer_assignments.opens_count', '>', 0)->count();
        $followedUp = $this->assignmentsQuery()->followedUp()->count();
        $interested = $this->assignmentsQuery()->whereNull('customer_assignments.converted_at')->whereLatestFollowUpStatus(['Interested'])->count();
        $converted = $this->assignmentsQuery()->whereNotNull('customer_assignments.converted_at')->count();
        $overdue = $this->assignmentsQuery()->overdueFollowUp()->count();
        $owners = $this->assignmentsQuery()->distinct()->count('customer_assignments.employee_id');
        $idleOwners = $this->assignmentsQuery()
            ->whereNotIn('customer_assignments.employee_id', $this->assignmentsQuery()->followedUp()->select('customer_assignments.employee_id'))
            ->distinct()
            ->count('customer_assignments.employee_id');
        $reassigned = CustomerAssignmentTransfer::query()
            ->whereIn('customer_assignment_id', $this->assignmentsQuery()->select('customer_assignments.id'))
            ->count();

        return [
            Stat::make('Leads Assigned', number_format($total))
                ->description("Held by {$owners} employee(s)")
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('primary'),

            Stat::make('Not Touched', number_format($untouched))
                ->description($percent($untouched).' — never opened, no remark')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($untouched > 0 ? 'danger' : 'success'),

            Stat::make('Opened', number_format($opened))
                ->description($percent($opened))
                ->icon('heroicon-o-eye')
                ->color('info'),

            Stat::make('Followed Up', number_format($followedUp))
                ->description($percent($followedUp).' have at least one remark')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info'),

            Stat::make('Interested (to convert)', number_format($interested))
                ->description('Latest remark is Interested, not yet converted')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('warning'),

            Stat::make('Converted', number_format($converted))
                ->description($percent($converted))
                ->icon('heroicon-o-check-badge')
                ->color('success'),

            Stat::make('Overdue Follow-Ups', number_format($overdue))
                ->description('Next follow-up date already passed')
                ->icon('heroicon-o-clock')
                ->color($overdue > 0 ? 'warning' : 'success'),

            Stat::make('Not Working', number_format($idleOwners))
                ->description("Employee(s) with no remark on any lead · {$reassigned} reassignment(s)")
                ->icon('heroicon-o-user-minus')
                ->color($idleOwners > 0 ? 'danger' : 'success'),
        ];
    }
}
