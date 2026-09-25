<?php

namespace App\Filament\Resources\LeadAssignmentReports\Tables;

use App\Filament\Exports\LeadAssignmentReportExporter;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Services\HierarchyService;
use App\Support\EmployeeOptions;
use App\Support\LeadAssignmentFilters;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LeadAssignmentReportsTable
{
    /**
     * Days without a follow-up after which someone holding leads is "Inactive".
     */
    public const INACTIVE_AFTER_DAYS = 3;

    /**
     * Follow-up remark type => count alias, for the per-remark breakdown.
     *
     * @var array<string, string>
     */
    public const REMARK_COUNTS = [
        'Interested' => 'interested_count',
        'Not Interested' => 'not_interested_count',
        'Call Back' => 'call_back_count',
        'Busy' => 'busy_count',
        'No Response' => 'no_response_count',
        'Not Eligible' => 'not_eligible_remark_count',
        'Eligible for Other Bank' => 'other_bank_count',
        'Pending' => 'pending_count',
    ];

    /**
     * Every per-employee count, constrained to the leads the report's filters
     * select (assigned-on window — the topbar month by default — template and
     * assigned-by), so the whole funnel describes the same set of leads.
     *
     * @param  array<string, mixed>|null  $filters  Table filter state.
     * @return array<string, Closure>
     */
    public static function funnelWithCount(?array $filters = null): array
    {
        $inScope = fn (Builder $q): Builder => LeadAssignmentFilters::scopeAssignments($q, $filters);
        $withJourney = fn (string $status): Closure => fn (Builder $q) => $inScope($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', $status));

        $counts = [
            'assignmentsReceived as assigned_count' => $inScope,
            'assignmentsReceived as untouched_count' => fn (Builder $q) => $inScope($q)->untouched(),
            'assignmentsReceived as opened_count' => fn (Builder $q) => $inScope($q)->where('customer_assignments.opens_count', '>', 0),
            'assignmentsReceived as followed_up_count' => fn (Builder $q) => $inScope($q)->followedUp(),
            'assignmentsReceived as overdue_count' => fn (Builder $q) => $inScope($q)->overdueFollowUp(),
            'assignmentsReceived as converted_count' => fn (Builder $q) => $inScope($q)->whereNotNull('customer_assignments.converted_at'),
            'assignmentsReceived as interested_open_count' => fn (Builder $q) => $inScope($q)->whereNull('customer_assignments.converted_at')->whereLatestFollowUpStatus(['Interested']),
            'assignmentsReceived as eligible_count' => fn (Builder $q) => $inScope($q)->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'eligible')),
            'assignmentsReceived as not_eligible_count' => fn (Builder $q) => $inScope($q)->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'not_eligible')),
            'assignmentsReceived as sfl_count' => $withJourney('sfl'),
            'assignmentsReceived as underwriting_count' => $withJourney('underwriting'),
            'assignmentsReceived as approved_count' => $withJourney('approved'),
            'assignmentsReceived as disbursed_count' => $withJourney('sanctioned'),
            'assignmentsReceived as completed_count' => $withJourney('completed'),
            'assignmentsReceived as carry_forward_count' => $withJourney('carry_forward'),
            'assignmentsReceived as dropped_count' => $withJourney('dropped'),
            'assignmentsReceived as not_approved_count' => $withJourney('not_approved'),
            'assignmentTransfersOut as reassigned_out_count' => fn (Builder $q) => $q->whereBetween('customer_assignment_transfers.created_at', LeadAssignmentFilters::assignedRange($filters)),
        ];

        foreach (self::REMARK_COUNTS as $status => $alias) {
            $counts["assignmentsReceived as {$alias}"] = fn (Builder $q) => $inScope($q)->whereLatestFollowUpStatus([$status]);
        }

        return $counts;
    }

    /**
     * Applies the report's lead scope to the employee query: only people
     * holding leads in scope, with every count and their last follow-up.
     *
     * @param  array<string, mixed>|null  $filters  Table filter state.
     */
    public static function applyReportScope(Builder $query, ?array $filters): Builder
    {
        [$start, $end] = LeadAssignmentFilters::assignedRange($filters);

        return $query
            ->activeDuring($start, $end)
            ->whereHas('assignmentsReceived', fn (Builder $q) => LeadAssignmentFilters::scopeAssignments($q, $filters))
            ->withCount(self::funnelWithCount($filters))
            ->addSelect(['last_activity_at' => FollowUp::query()
                ->selectRaw('MAX(follow_ups.created_at)')
                ->whereColumn('follow_ups.employee_id', 'employees.id')]);
    }

    protected static function percentOf(string $count): Closure
    {
        return function (Employee $record) use ($count): string {
            if (! $record->assigned_count) {
                return '0%';
            }

            return round(($record->{$count} / $record->assigned_count) * 100).'%';
        };
    }

    /**
     * Whether the employee is actually working the leads they hold.
     */
    public static function workStatus(Employee $record): string
    {
        $lastActivity = $record->last_activity_at ? Carbon::parse($record->last_activity_at) : null;

        return match (true) {
            ! $record->assigned_count => 'No Leads',
            ! $record->followed_up_count && ! $record->opened_count => 'Not Working',
            ! $record->followed_up_count => 'Opened Only',
            ! $lastActivity || $lastActivity->lt(now()->subDays(self::INACTIVE_AFTER_DAYS)) => 'Inactive',
            default => 'Active',
        };
    }

    /**
     * The most useful next step for this employee's leads.
     */
    public static function suggestedAction(Employee $record): string
    {
        $retry = (int) $record->busy_count + (int) $record->no_response_count;

        return match (true) {
            ! $record->assigned_count => '-',
            in_array(self::workStatus($record), ['Not Working', 'Inactive'], true) && $record->untouched_count > 0 => "Reassign {$record->untouched_count} untouched lead(s)",
            $record->untouched_count * 2 >= $record->assigned_count => "Push to work {$record->untouched_count} untouched lead(s)",
            $record->overdue_count > 0 => "Clear {$record->overdue_count} overdue follow-up(s)",
            $record->interested_open_count > 0 => "Convert {$record->interested_open_count} interested lead(s)",
            $retry > 0 => "Retry {$retry} busy / no-response lead(s)",
            $record->untouched_count > 0 => "Work {$record->untouched_count} untouched lead(s)",
            default => 'On track',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query, $livewire): Builder => self::applyReportScope($query, data_get($livewire, 'tableFilters')))
            ->defaultSort('assigned_count', 'desc')
            ->columns([
                TextColumn::make('emp_name')
                    ->label('User')
                    ->description(fn (Employee $record): ?string => $record->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('emp_id')
                    ->label('Emp ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('designation')
                    ->label('Role')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state)
                    ->badge()
                    ->sortable(),

                TextColumn::make('work_status')
                    ->label('Work Status')
                    ->badge()
                    ->state(fn (Employee $record): string => self::workStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Opened Only', 'Inactive' => 'warning',
                        'Not Working' => 'danger',
                        default => 'gray',
                    }),

                ColumnGroup::make('Assigned Leads', [
                    TextColumn::make('assigned_count')
                        ->label('Assigned')
                        ->weight('bold')
                        ->sortable(),

                    TextColumn::make('untouched_count')
                        ->label('Not Touched')
                        ->sortable()
                        ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                        ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('untouched_count')($record).')'),

                    TextColumn::make('opened_count')
                        ->label('Opened')
                        ->sortable()
                        ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('opened_count')($record).')'),

                    TextColumn::make('followed_up_count')
                        ->label('Followed Up')
                        ->sortable()
                        ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('followed_up_count')($record).')'),

                    TextColumn::make('converted_count')
                        ->label('Converted')
                        ->sortable()
                        ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                        ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('converted_count')($record).')'),

                    TextColumn::make('overdue_count')
                        ->label('Overdue')
                        ->sortable()
                        ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),

                    TextColumn::make('reassigned_out_count')
                        ->label('Reassigned Away')
                        ->sortable()
                        ->toggleable(),
                ]),

                ColumnGroup::make('Latest Remark', collect(self::REMARK_COUNTS)
                    ->map(fn (string $alias, string $status): TextColumn => TextColumn::make($alias)
                        ->label($status === 'Eligible for Other Bank' ? 'Other Bank' : $status)
                        ->sortable()
                        ->toggleable())
                    ->values()
                    ->all()),

                ColumnGroup::make('Journey', [
                    TextColumn::make('approved_count')
                        ->label('Approved')
                        ->sortable()
                        ->toggleable(),

                    TextColumn::make('disbursed_count')
                        ->label('Disbursed')
                        ->sortable()
                        ->toggleable(),

                    TextColumn::make('eligible_count')
                        ->label('Eligible')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('not_eligible_count')
                        ->label('Not Eligible')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('sfl_count')
                        ->label('SFL')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('underwriting_count')
                        ->label('Underwriting')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('completed_count')
                        ->label('Completed')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('carry_forward_count')
                        ->label('Carry Forward')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('dropped_count')
                        ->label('Dropped')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('not_approved_count')
                        ->label('Not Approved')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]),

                TextColumn::make('last_activity_at')
                    ->label('Last Follow-Up')
                    ->dateTime('d M Y h:i A')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('suggested_action')
                    ->label('Suggested Action')
                    ->state(fn (Employee $record): string => self::suggestedAction($record))
                    ->wrap(),
            ])
            ->filters([
                LeadAssignmentFilters::assignedOnFilter(),

                SelectFilter::make('id')
                    ->label('User')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo(Filament::auth()->user())),

                SelectFilter::make('designation')
                    ->label('Role')
                    ->multiple()
                    ->options(fn (): array => Employee::designationOptions()),

                LeadAssignmentFilters::templateFilter(appliesToQuery: false),

                LeadAssignmentFilters::assignedByFilter(appliesToQuery: false),

                SelectFilter::make('attention')
                    ->label('Needs Attention')
                    ->options([
                        'not_working' => 'Not working (no follow-up on any lead)',
                        'untouched' => 'Has untouched leads',
                        'overdue' => 'Has overdue follow-ups',
                        'interested' => 'Has interested leads to convert',
                    ])
                    ->query(fn (Builder $query, array $data, $livewire): Builder => $query->when(
                        filled($data['value'] ?? null),
                        function (Builder $query) use ($data, $livewire): Builder {
                            $filters = data_get($livewire, 'tableFilters');
                            $inScope = fn (Builder $q): Builder => LeadAssignmentFilters::scopeAssignments($q, $filters);

                            return match ($data['value']) {
                                'not_working' => $query->whereDoesntHave('assignmentsReceived', fn (Builder $q) => $inScope($q)->followedUp()),
                                'untouched' => $query->whereHas('assignmentsReceived', fn (Builder $q) => $inScope($q)->untouched()),
                                'overdue' => $query->whereHas('assignmentsReceived', fn (Builder $q) => $inScope($q)->overdueFollowUp()),
                                default => $query->whereHas('assignmentsReceived', fn (Builder $q) => $inScope($q)->whereNull('customer_assignments.converted_at')->whereLatestFollowUpStatus(['Interested'])),
                            };
                        }
                    )),
            ])
            ->recordActions([
                Action::make('viewLeads')
                    ->label('View Leads')
                    ->icon('heroicon-o-queue-list')
                    ->color('gray')
                    ->url(fn (Employee $record, $livewire): string => AssignedLeadResource::getUrl('index', [
                        'filters' => array_filter([
                            'employee_id' => ['values' => [$record->id]],
                            'assigned_on' => data_get($livewire, 'tableFilters.assigned_on'),
                            'template' => data_get($livewire, 'tableFilters.template'),
                            'assigned_by' => data_get($livewire, 'tableFilters.assigned_by'),
                        ]),
                    ])),

                Action::make('reassignUntouched')
                    ->label('Reassign Untouched')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->visible(fn (Employee $record): bool => $record->untouched_count > 0)
                    ->modalHeading(fn (Employee $record): string => "Reassign {$record->emp_name}'s untouched leads")
                    ->modalDescription(fn (Employee $record): string => "{$record->untouched_count} lead(s) in this report were never opened or followed up. They will move to the employee you pick.")
                    ->schema(fn (Employee $record): array => [
                        Select::make('employee_id')
                            ->label('Reassign To')
                            ->options(fn (): array => collect(EmployeeOptions::visibleTo(Filament::auth()->user()))->except([$record->id])->all())
                            ->required(),

                        Textarea::make('reason')
                            ->label('Reason')
                            ->default('Untouched by previous owner')
                            ->rows(2),
                    ])
                    ->action(function (Employee $record, array $data, $livewire): void {
                        $user = Filament::auth()->user();
                        $targetId = (int) $data['employee_id'];

                        if (! $user instanceof User || ! in_array($targetId, HierarchyService::visibleEmployeeIds($user))) {
                            Notification::make()->title('You cannot reassign leads to that employee.')->danger()->send();

                            return;
                        }

                        $leads = LeadAssignmentFilters::scopeAssignments(
                            CustomerAssignment::query()->where('employee_id', $record->id),
                            data_get($livewire, 'tableFilters'),
                        )->untouched()->get();

                        $result = app(CustomerAssignmentService::class)->reassign($leads, $targetId, $user->employee?->id, $data['reason'] ?? null);

                        Notification::make()->title("{$result['reassigned']} lead(s) reassigned")->success()->send();
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeadAssignmentReportExporter::class)
                    ->label('Download Report'),
            ]);
    }
}
