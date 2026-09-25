<?php

namespace App\Filament\Resources\AssignedLeads\Tables;

use App\Filament\Actions\DropFollowUpActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Services\FollowUpReminderService;
use App\Services\HierarchyService;
use App\Support\EmployeeOptions;
use App\Support\LeadAssignmentFilters;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AssignedLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Prospect Name')
                    // Name lives on either the customer or the AI record, so
                    // sorting orders by whichever one this row points at.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Customer::query()
                            ->select('customer_name')
                            ->whereColumn('customers.id', 'customer_assignments.customer_id')
                            ->limit(1),
                        $direction,
                    )),

                TextColumn::make('template.name')
                    ->label('Template')
                    ->badge()
                    ->color('info')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->description(fn (CustomerAssignment $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => self::canSeeTeam()),

                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn (): bool => self::canSeeTeam()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // "Opened"/"Pending" is derived from the open counter.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('opens_count', $direction))
                    ->color(fn (string $state): string => $state === 'Opened' ? 'success' : 'gray'),

                TextColumn::make('opens_count')
                    ->label('Opens')
                    ->sortable(),

                TextColumn::make('follow_up_status')
                    ->label('Follow-Up Status')
                    ->badge()
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUpStatus() ?? 'Pending')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        CustomerAssignment::latestFollowUpValueQuery('status'),
                        $direction,
                    ))
                    ->color(fn (string $state): string => match ($state) {
                        'Interested' => 'success',
                        'Not Interested', 'Not Eligible', 'Dropped', 'Lost' => 'danger',
                        'Busy', 'No Response', 'Call Back' => 'warning',
                        'Eligible for Other Bank' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('next_follow_up')
                    ->label('Next Follow Up')
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUp()?->next_follow_up_date)
                    ->dateTime('d M Y h:i A')
                    ->placeholder('-'),

                TextColumn::make('customer.journey_status')
                    ->label('Journey')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'sanctioned' => 'Disbursed',
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'sfl' => 'gray',
                        'underwriting' => 'warning',
                        'approved' => 'info',
                        'sanctioned' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('assignedBy.emp_name')
                    ->label('Assigned By')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Assigned On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('reassign_count')
                    ->label('Reassigned')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state}x" : '-')
                    ->description(fn (CustomerAssignment $record): ?string => $record->last_reassigned_at?->format('d M Y h:i A'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('converted_at')
                    ->label('Converted On')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_opened_at')
                    ->label('Last Opened')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                LeadAssignmentFilters::assignedOnFilter(),

                LeadAssignmentFilters::assignedByFilter(),

                SelectFilter::make('employee_id')
                    ->label('Case Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo(Filament::auth()->user()))
                    ->visible(fn (): bool => self::canSeeTeam()),

                SelectFilter::make('emp_id')
                    ->label('Emp ID')
                    ->multiple()
                    ->options(fn (): array => Employee::query()
                        ->whereIn('id', array_keys(EmployeeOptions::visibleTo(Filament::auth()->user())))
                        ->whereNotNull('emp_id')
                        ->orderBy('emp_id')
                        ->pluck('emp_id', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query) => $query->whereIn('customer_assignments.employee_id', $data['values'])
                    ))
                    ->visible(fn (): bool => self::canSeeTeam()),

                LeadAssignmentFilters::templateFilter(),

                SelectFilter::make('open_status')
                    ->label('Status')
                    ->options([
                        'opened' => 'Opened',
                        'pending' => 'Pending',
                        'untouched' => 'Not Touched (never opened, no follow-up)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => match ($data['value']) {
                            'opened' => $query->where('opens_count', '>', 0),
                            'untouched' => $query->untouched(),
                            default => $query->where('opens_count', 0),
                        }
                    )),

                SelectFilter::make('follow_up_status')
                    ->label('Follow-Up Status')
                    ->multiple()
                    ->options(CustomerAssignment::FOLLOW_UP_STATUSES)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query) => $query->whereLatestFollowUpStatus(array_values($data['values']))
                    )),

                SelectFilter::make('journey_status')
                    ->label('Journey')
                    ->multiple()
                    ->options([
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        'sanctioned' => 'Disbursed',
                        'completed' => 'Completed',
                        'carry_forward' => 'Carry Forward',
                        'dropped' => 'Dropped',
                        'not_approved' => 'Not Approved',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query) => $query->whereHas('customer', fn (Builder $query) => $query->whereIn('journey_status', $data['values']))
                    )),

                TernaryFilter::make('converted')
                    ->label('Converted')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('customer_assignments.converted_at'),
                        false: fn (Builder $query) => $query->whereNull('customer_assignments.converted_at'),
                    ),

                TernaryFilter::make('reassigned')
                    ->label('Reassigned')
                    ->queries(
                        true: fn (Builder $query) => $query->where('customer_assignments.reassign_count', '>', 0),
                        false: fn (Builder $query) => $query->where('customer_assignments.reassign_count', 0),
                    ),

                TernaryFilter::make('overdue')
                    ->label('Follow-Up Overdue')
                    ->queries(
                        true: fn (Builder $query) => $query->overdueFollowUp(),
                        false: fn (Builder $query) => $query->whereNotIn('customer_assignments.id', CustomerAssignment::query()->overdueFollowUp()->select('customer_assignments.id')),
                    ),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('convertToCustomer')
                    ->label('Convert')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerAssignment $record) => $record->isEligibleForConversion())
                    ->url(fn (CustomerAssignment $record) => CustomerResource::getUrl('create', [
                        'ai_customer_record' => $record->ai_customer_record_id,
                    ])),

                self::reassignAction(),

                DropFollowUpActions::record(fn (CustomerAssignment $record) => $record->latestFollowUp()),

                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::reassignBulkAction(),

                    DropFollowUpActions::bulk(fn (Collection $records) => app(FollowUpReminderService::class)->currentForAssignments($records)),
                ]),
            ])
            ->modifyQueryUsing(
                fn (Builder $query, $livewire): Builder => $query->whereBetween(
                    'customer_assignments.created_at',
                    LeadAssignmentFilters::assignedRange(data_get($livewire, 'tableFilters')),
                )
            );
    }

    /**
     * Callers only see their own leads, so team columns, filters and
     * reassignment are for Admin and the supervisory designations.
     */
    public static function canSeeTeam(): bool
    {
        $user = Filament::auth()->user();

        return (bool) ($user?->hasRole('Admin')
            || ($user?->employee && $user->employee->designation !== Employee::DESIGNATION_CALLER));
    }

    public static function reassignAction(): Action
    {
        return Action::make('reassign')
            ->label('Reassign')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->visible(fn (): bool => self::canSeeTeam())
            ->modalHeading('Reassign Lead')
            ->modalDescription(fn (CustomerAssignment $record): string => "Currently with {$record->employee?->emp_name}. The lead can be reassigned again later; every move is logged.")
            ->schema(fn (CustomerAssignment $record): array => self::reassignSchema([$record->employee_id]))
            ->action(fn (CustomerAssignment $record, array $data) => self::performReassign(collect([$record]), $data));
    }

    public static function reassignBulkAction(): BulkAction
    {
        return BulkAction::make('reassign')
            ->label('Reassign Selected')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->visible(fn (): bool => self::canSeeTeam())
            ->modalHeading('Reassign Selected Leads')
            ->schema(self::reassignSchema())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => self::performReassign($records, $data));
    }

    /**
     * @param  list<int|null>  $excludedEmployeeIds
     * @return array<int, Select|Textarea>
     */
    protected static function reassignSchema(array $excludedEmployeeIds = []): array
    {
        return [
            Select::make('employee_id')
                ->label('Reassign To')
                ->options(fn (): array => collect(EmployeeOptions::visibleTo(Filament::auth()->user()))
                    ->except(array_filter($excludedEmployeeIds))
                    ->all())
                ->required(),

            Textarea::make('reason')
                ->label('Reason')
                ->rows(3),
        ];
    }

    /**
     * @param  Collection<int, CustomerAssignment>  $records
     * @param  array{employee_id: int|string, reason?: string|null}  $data
     */
    protected static function performReassign(Collection $records, array $data): void
    {
        $user = Filament::auth()->user();
        $targetId = (int) $data['employee_id'];

        // The dropdown is already limited to the viewer's branch; re-check so
        // a tampered request cannot hand leads outside it.
        if (! $user instanceof User || ! self::canSeeTeam() || ! in_array($targetId, HierarchyService::visibleEmployeeIds($user))) {
            Notification::make()->title('You cannot reassign leads to that employee.')->danger()->send();

            return;
        }

        $result = app(CustomerAssignmentService::class)->reassign(
            $records,
            $targetId,
            $user->employee?->id,
            $data['reason'] ?? null,
        );

        Notification::make()
            ->title("{$result['reassigned']} lead(s) reassigned")
            ->body($result['skipped'] ? "{$result['skipped']} already belonged to that employee." : null)
            ->success()
            ->send();
    }
}
