<?php

namespace App\Filament\Resources\AiCustomerRecords\Tables;

use App\Filament\Actions\AssignCustomersToUserBulkAction;
use App\Filament\Imports\AiCustomerRecordImporter;
// use Filament\Actions\BulkActionGroup;
// use Filament\Actions\DeleteBulkAction;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Services\CustomerAssignmentService;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class AiCustomerRecordsTable
{
    /**
     * Shown in place of the stored status once a record has been handed to
     * an employee. Display-only: the row's own status column is untouched.
     */
    public const STATUS_ASSIGNED = 'assigned';

    public static function configure(Table $table): Table
    {
        $dynamicColumns = [];

        try {
            $fields = AiDocumentSchema::query()->get()
                ->flatMap(fn (AiDocumentSchema $schema) => $schema->getFieldDefinitions())
                ->filter(fn ($field) => filled($field['key'] ?? null))
                ->unique('key')
                ->values();

            foreach ($fields as $field) {
                $key = (string) $field['key'];
                $label = (string) ($field['label'] ?? $key);
                $dynamicColumns[] = TextColumn::make("data.$key")
                    ->label($label)
                    ->searchable()
                    ->toggleable();
            }
        } catch (Throwable) {
            // During the first deployment the schema table may not exist yet.
        }

        return $table
            ->defaultSort('id', 'desc')
            ->columns(array_merge([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('schema.name')->label('Configuration')->searchable()->sortable(),
                TextColumn::make('customer.customer_name')->label('Customer')->searchable()->sortable()->default('-'),
                TextColumn::make('document.original_name')->label('Source Document')->limit(30)->searchable()->sortable(),
                TextColumn::make('latestAssignment.employee.emp_name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned')
                    ->description(fn (AiCustomerRecord $record): ?string => $record->latestAssignment?->employee?->emp_id)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('latestAssignment.employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (AiCustomerRecord $record): string => $record->latestAssignment ? self::STATUS_ASSIGNED : $record->status)
                    ->searchable()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'CASE WHEN EXISTS ('.self::assignmentExistsSql().') THEN ? ELSE status END '.($direction === 'desc' ? 'DESC' : 'ASC'),
                        [self::STATUS_ASSIGNED],
                    ))
                    ->color(fn (string $state): string => match ($state) {
                        self::STATUS_ASSIGNED => 'info',
                        'approved' => 'success',
                        'review' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state * 100, 1).'%'),
            ], $dynamicColumns))
            ->filters([
                SelectFilter::make('schema_id')->label('Configuration')->relationship('schema', 'name')->searchable()->preload(),
                SelectFilter::make('status')
                    ->options([
                        self::STATUS_ASSIGNED => 'Assigned',
                        'review' => 'Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'pending' => 'Pending',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $data['value'] === self::STATUS_ASSIGNED
                            ? $query->whereHas('assignments')
                            : $query->where('status', $data['value'])->whereDoesntHave('assignments'),
                    )),
                TernaryFilter::make('assigned')
                    ->label('Assignment')
                    ->placeholder('All records')
                    ->trueLabel('Assigned')
                    ->falseLabel('Not assigned')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('assignments'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('assignments'),
                    ),
                SelectFilter::make('assigned_to')
                    ->label('Assigned To')
                    ->multiple()
                    ->options(fn (): array => self::assigneeOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query) => $query->whereHas(
                            'assignments',
                            fn (Builder $assignments) => $assignments->whereIn('employee_id', $data['values']),
                        ),
                    )),
            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with('latestAssignment.employee')
                    ->whereBetween('created_at', SelectedMonth::range())
            )
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->headerActions([
                self::assignBySerialNumberAction(),
                ImportAction::make()
                    ->label('Import Customer Data')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->importer(AiCustomerRecordImporter::class),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records = $records->filter(
                                fn (AiCustomerRecord $record) => $record->status === 'review'
                            );

                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('No records available for approval')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $now = now();

                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'approved',
                                    'reviewed_by' => auth()->id(),
                                    'reviewed_at' => $now,
                                    'rejection_reason' => null,
                                ]);
                            }

                            Notification::make()
                                ->title("{$records->count()} records approved")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('reject')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->form([
                            Textarea::make('reason')
                                ->label('Rejection Reason')
                                ->required()
                                ->minLength(5)
                                ->maxLength(1000),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records = $records->filter(
                                fn (AiCustomerRecord $record) => $record->status === 'review'
                            );

                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('No records available for rejection')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $now = now();

                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'rejected',
                                    'reviewed_by' => auth()->id(),
                                    'reviewed_at' => $now,
                                    'rejection_reason' => $data['reason'],
                                ]);
                            }

                            Notification::make()
                                ->title("{$records->count()} records rejected")
                                ->danger()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),

                    AssignCustomersToUserBulkAction::make(
                        fn (AiCustomerRecord $record) => $record->id,
                        'ai_customer_record',
                    ),
                ]),
            ]);
    }

    /**
     * Employees who currently hold at least one AI record, so the filter only
     * offers names that can actually narrow the list.
     *
     * @return array<int, string>
     */
    private static function assigneeOptions(): array
    {
        return Employee::query()
            ->whereIn('id', CustomerAssignment::query()->whereNotNull('ai_customer_record_id')->select('employee_id'))
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
            ->all();
    }

    /**
     * Hands every unassigned record whose # (serial number) falls in the
     * typed range to one employee, so a slice of a fresh upload can be
     * given out without ticking rows one page at a time.
     */
    private static function assignBySerialNumberAction(): Action
    {
        return Action::make('assignBySerialNumber')
            ->label('Assign by S. No.')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn () => auth()->user()?->hasRole('Admin') ?? false)
            ->modalHeading('Assign records by serial number')
            ->modalDescription('Every record whose # is inside the range and is not yet assigned goes to the selected user.')
            ->modalSubmitActionLabel('Assign')
            ->schema([
                TextInput::make('from_id')
                    ->label('From S. No.')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->live(onBlur: true),
                TextInput::make('to_id')
                    ->label('To S. No.')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->gte('from_id')
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => self::rangeSummary($get('from_id'), $get('to_id'))),
                Select::make('employee_id')
                    ->label('Assign To')
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_CALLER)
                        + EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $targetIds = self::recordIdsInRange((int) $data['from_id'], (int) $data['to_id']);

                if ($targetIds->isEmpty()) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('No records exist in that serial number range.')
                        ->warning()
                        ->send();

                    return;
                }

                $result = app(CustomerAssignmentService::class)->assign(
                    $targetIds,
                    (int) $data['employee_id'],
                    CustomerAssignmentService::TARGET_AI_RECORD,
                    auth()->user()?->employee?->id,
                );

                if ($result['assigned'] === 0) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('Every record in that range is already assigned to a user.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Assigned')
                    ->body(
                        $result['assigned'].' record(s) assigned.'
                        .($result['skipped'] ? " {$result['skipped']} were already assigned and skipped." : '')
                    )
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Collection<int, int>
     */
    private static function recordIdsInRange(int $fromId, int $toId): Collection
    {
        return AiCustomerRecord::query()
            ->whereBetween('id', [min($fromId, $toId), max($fromId, $toId)])
            ->orderBy('id')
            ->pluck('id');
    }

    private static function rangeSummary(mixed $fromId, mixed $toId): string
    {
        if (! is_numeric($fromId) || ! is_numeric($toId) || (int) $fromId < 1 || (int) $toId < 1) {
            return 'Enter both serial numbers to see how many records will be assigned.';
        }

        $ids = self::recordIdsInRange((int) $fromId, (int) $toId);

        if ($ids->isEmpty()) {
            return 'No records in this range.';
        }

        $alreadyAssigned = CustomerAssignment::query()->whereIn('ai_customer_record_id', $ids)->count();
        $toAssign = $ids->count() - $alreadyAssigned;

        return "{$ids->count()} record(s) in range: {$toAssign} will be assigned, {$alreadyAssigned} already assigned.";
    }

    private static function assignmentExistsSql(): string
    {
        return 'SELECT 1 FROM customer_assignments WHERE customer_assignments.ai_customer_record_id = ai_customer_records.id';
    }
}
