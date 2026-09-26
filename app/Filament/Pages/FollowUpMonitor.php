<?php

namespace App\Filament\Pages;

use App\Enums\FollowUpOutcome;
use App\Filament\Actions\FollowUpBacklogActions;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUpMonitorService;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use BackedEnum;
use Carbon\CarbonPeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Supervisors' view of how their team keeps follow-ups: on-time rate,
 * missed and late counts, how old the open backlog is, who is booked past
 * the daily limit, and a per-caller missed heat grid. Picking a caller
 * lists their missed follow-ups with bulk spread / drop.
 *
 * Admin sees everyone; a Team Leader, Manager, Cluster Manager or Business
 * Head sees their own branch (see FollowUpMonitorService::scopedQueryFor()).
 */
class FollowUpMonitor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Follow-up Monitor';

    protected static ?string $title = 'Follow-up Monitor';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.follow-up-monitor';

    public ?array $filters = [];

    public ?int $focusEmployeeId = null;

    public function mount(): void
    {
        [$start, $end] = SelectedMonth::range();

        $this->form->fill([
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'module' => FollowUpMonitorService::MODULE_ALL,
            'employee_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')
                    ->label('Due from')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->required()
                    ->live(),
                DatePicker::make('to')
                    ->label('Due to')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->required()
                    ->afterOrEqual('from')
                    ->live(),
                Select::make('module')
                    ->label('Follow-ups')
                    ->options(FollowUpMonitorService::moduleOptions())
                    ->searchable(false)
                    ->selectablePlaceholder(false)
                    ->live(),
                Select::make('employee_id')
                    ->label('Employee')
                    ->placeholder('Whole team')
                    ->options(fn (): array => EmployeeOptions::visibleTo())
                    ->live(),
            ])
            ->columns(4)
            ->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->focusEmployeeId = null;
        unset($this->report);
    }

    public function focusEmployee(int $employeeId): void
    {
        $this->focusEmployeeId = $this->isAdmin() || $this->visibleEmployeeIds()->contains($employeeId) ? $employeeId : null;
    }

    public function clearFocus(): void
    {
        $this->focusEmployeeId = null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(): array
    {
        $from = filled($this->filters['from'] ?? null) ? Carbon::parse($this->filters['from']) : SelectedMonth::range()[0];
        $to = filled($this->filters['to'] ?? null) ? Carbon::parse($this->filters['to']) : SelectedMonth::range()[1];

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    /**
     * @return array{
     *     summary: array<string, int|float|null>,
     *     backlog: array<string, int>,
     *     open_overdue: int,
     *     overloaded_next_week: int,
     *     team: list<array<string, mixed>>,
     *     heat_dates: list<string>,
     *     heat: array<int, array<string, int>>,
     *     statuses: array<string, int>,
     * }
     */
    #[Computed]
    public function report(): array
    {
        $monitor = app(FollowUpMonitorService::class);
        [$from, $to] = $this->period();

        $classified = $monitor->classify($this->monitoredQuery(), $from, $to);
        $backlog = $monitor->backlogAges($this->monitoredQuery());

        $nextWeekLoad = $monitor->openLoad($this->monitoredQuery(), today(), today()->addDays(6)->endOfDay());
        $overloaded = collect($monitor->overloadedDays($nextWeekLoad));

        $openOverdueByEmployee = $this->monitoredQuery()
            ->latestPerSubject()
            ->scheduled()
            ->where('follow_ups.next_follow_up_date', '<', now())
            ->get(['follow_ups.id', 'follow_ups.employee_id'])
            ->countBy(fn (FollowUp $followUp): int => (int) $followUp->employee_id);

        $byEmployee = $classified->groupBy(fn (FollowUp $followUp): int => (int) $followUp->employee_id);

        $employeeIds = $byEmployee->keys()
            ->merge($openOverdueByEmployee->keys())
            ->merge(array_keys($nextWeekLoad))
            ->unique()
            ->values();

        $employees = Employee::query()->whereIn('id', $employeeIds->filter())->get()->keyBy('id');

        $team = $employeeIds
            ->map(function (int $employeeId) use ($byEmployee, $monitor, $openOverdueByEmployee, $nextWeekLoad, $overloaded, $employees): array {
                $employee = $employees->get($employeeId);

                return [
                    'employee_id' => $employeeId,
                    'name' => $employee?->emp_name ?? 'Admin / unassigned',
                    'emp_id' => $employee?->emp_id,
                    ...$monitor->summarize($byEmployee->get($employeeId, collect())),
                    'open_overdue' => $openOverdueByEmployee->get($employeeId, 0),
                    'next_week' => array_sum($nextWeekLoad[$employeeId] ?? []),
                    'overloaded_days' => $overloaded->where('employee_id', $employeeId)->count(),
                ];
            })
            ->sortBy([['missed', 'desc'], ['open_overdue', 'desc'], ['on_time_rate', 'asc']])
            ->values()
            ->all();

        $heatDates = collect(CarbonPeriod::create($from, $to->copy()->min(now())))
            ->map(fn ($date): string => $date->toDateString())
            ->take(-31)
            ->values()
            ->all();

        $heat = $classified
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === FollowUpOutcome::Missed)
            ->groupBy(fn (FollowUp $followUp): int => (int) $followUp->employee_id)
            ->map(fn (Collection $rows): array => $rows
                ->countBy(fn (FollowUp $followUp): string => $followUp->next_follow_up_date->toDateString())
                ->all())
            ->all();

        $statuses = $this->monitoredQuery()
            ->whereBetween('follow_ups.created_at', [$from, $to])
            ->get(['follow_ups.id', 'follow_ups.status'])
            ->countBy(fn (FollowUp $followUp): string => $followUp->status ?: 'Pending')
            ->sortDesc()
            ->all();

        return [
            'summary' => $monitor->summarize($classified),
            'backlog' => $backlog,
            'open_overdue' => array_sum($backlog),
            'overloaded_next_week' => $overloaded->count(),
            'team' => $team,
            'heat_dates' => $heatDates,
            'heat' => $heat,
            'statuses' => $statuses,
        ];
    }

    /**
     * The focused employee's missed follow-ups in the period — each still
     * the prospect's current row, so they can be spread or dropped.
     *
     * @return Collection<int, FollowUp>
     */
    public function focusedMissedFollowUps(): Collection
    {
        if ($this->focusEmployeeId === null) {
            return collect();
        }

        [$from, $to] = $this->period();

        $query = $this->monitoredQuery()
            ->where('follow_ups.employee_id', $this->focusEmployeeId)
            ->with(['customer', 'aiCustomerRecord', 'lead']);

        return app(FollowUpMonitorService::class)
            ->classify($query, $from, $to)
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === FollowUpOutcome::Missed)
            ->sortBy('next_follow_up_date')
            ->values();
    }

    public function focusedEmployee(): ?Employee
    {
        return $this->focusEmployeeId ? Employee::find($this->focusEmployeeId) : null;
    }

    public function spreadFocusedAction(): Action
    {
        return FollowUpBacklogActions::spread(
            'spreadFocused',
            fn (): Collection => $this->focusedMissedFollowUps(),
            fn () => $this->refreshReport(),
        );
    }

    public function dropFocusedAction(): Action
    {
        return FollowUpBacklogActions::drop(
            'dropFocused',
            fn (): Collection => $this->focusedMissedFollowUps(),
            fn () => $this->refreshReport(),
        );
    }

    public function refreshReport(): void
    {
        unset($this->report);
    }

    /**
     * @return Builder<FollowUp>
     */
    protected function monitoredQuery(): Builder
    {
        $employeeId = filled($this->filters['employee_id'] ?? null) ? (int) $this->filters['employee_id'] : null;

        return app(FollowUpMonitorService::class)->scopedQueryFor(
            $this->user(),
            $this->filters['module'] ?? FollowUpMonitorService::MODULE_ALL,
            $employeeId,
        );
    }

    /**
     * @return Collection<int, int>
     */
    protected function visibleEmployeeIds(): Collection
    {
        return HierarchyHelper::visibleEmployeeIds($this->user());
    }

    protected function isAdmin(): bool
    {
        return $this->user()->hasRole('Admin');
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        return in_array($user->employee?->designation, [
            Employee::DESIGNATION_BUSINESS_HEAD,
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
        ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
