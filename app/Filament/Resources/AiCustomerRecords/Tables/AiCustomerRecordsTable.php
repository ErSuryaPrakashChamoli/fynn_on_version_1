<?php

namespace App\Filament\Resources\AiCustomerRecords\Tables;

use App\Filament\Actions\AssignCustomersToUserBulkAction;
use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Actions\ImportCustomerDataAction;
use App\Filament\Exports\AiCustomerRecordExporter;
// use Filament\Actions\BulkActionGroup;
// use Filament\Actions\DeleteBulkAction;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Services\CustomerAssignmentService;
use App\Support\AiRecordSerialRanges;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                DataDownloadApproval::protect(
                    ExportAction::make()
                        ->exporter(AiCustomerRecordExporter::class)
                        ->label('Export Customer Data')
                        ->icon('heroicon-o-arrow-down-tray'),
                    'customer data',
                ),
                ImportCustomerDataAction::make(),
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
     * Hands out slices of Customer Data by # (serial number): each row is a
     * From/To range and the user who gets every unassigned record in it, so
     * a fresh upload can be split between several users in one go. The
     * still-unassigned runs are listed on top to pick from.
     */
    private static function assignBySerialNumberAction(): Action
    {
        return Action::make('assignBySerialNumber')
            ->label('Assign by S. No.')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn () => auth()->user()?->hasRole('Admin') ?? false)
            ->modalHeading('Assign records by serial number')
            ->modalDescription('Add a row per range. Every record whose # is inside a row\'s range and is not yet assigned goes to that row\'s user.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Assign')
            ->schema(function (): array {
                $unassignedRanges = AiRecordSerialRanges::unassigned();
                $employeeOptions = EmployeeOptions::forDesignation(Employee::DESIGNATION_CALLER)
                    + EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER);

                return [
                    View::make('filament.components.unassigned-serial-ranges')
                        ->viewData(['ranges' => $unassignedRanges]),
                    Repeater::make('ranges')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('unassigned_range')
                                ->label('Pick an unassigned range (optional)')
                                ->placeholder(fn (Get $get, Component $component): string => self::rangesAboveRow($get, $component) === []
                                    ? 'Fill From / To from the list above'
                                    : 'Fill From / To from what the ranges above left free')
                                ->options(fn (Get $get, Component $component): array => AiRecordSerialRanges::without(
                                    $unassignedRanges,
                                    self::rangesAboveRow($get, $component),
                                )->mapWithKeys(fn (array $range): array => [
                                    "{$range['from']}-{$range['to']}" => AiRecordSerialRanges::label($range)
                                        ." · {$range['count']} ".str('record')->plural($range['count'])
                                        ." · {$range['configuration']}",
                                ])->all())
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if (blank($state)) {
                                        return;
                                    }

                                    [$fromId, $toId] = explode('-', $state);
                                    $set('from_id', (int) $fromId);
                                    $set('to_id', (int) $toId);
                                    // Only a shortcut: cleared so it never shows a range the
                                    // From / To boxes no longer match.
                                    $set('unassigned_range', null);
                                })
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            TextInput::make('from_id')
                                ->label('From S. No.')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->live(onBlur: true)
                                ->rule(fn (Get $get, Component $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $component): void {
                                    if ($clash = self::overlapWithRangesAbove($get, $component)) {
                                        $fail("S. No. {$clash['label']} is already in Range {$clash['row']}. Each record can go to one user only.");
                                    }
                                }),
                            TextInput::make('to_id')
                                ->label('To S. No.')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->gte('from_id')
                                ->live(onBlur: true),
                            Select::make('employee_id')
                                ->label('Assign To')
                                ->options($employeeOptions)
                                ->required(),
                            Text::make(fn (Get $get, Component $component): string => self::rangeSummary($get, $component))
                                ->color(fn (Get $get, Component $component): string => self::overlapWithRangesAbove($get, $component) ? 'danger' : 'gray')
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->required()
                        ->addActionLabel('Add another range')
                        ->reorderable(false)
                        ->itemLabel(fn (?int $index): string => 'Range '.(($index ?? 0) + 1)),
                ];
            })
            ->action(function (array $data): void {
                $assignedBy = auth()->user()?->employee?->id;
                $employeeNames = Employee::query()
                    ->whereIn('id', collect($data['ranges'])->pluck('employee_id'))
                    ->get(['id', 'emp_name', 'emp_id'])
                    ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)]);

                $lines = DB::transaction(fn (): array => collect($data['ranges'])
                    ->map(function (array $range) use ($assignedBy, $employeeNames): array {
                        $fromId = (int) $range['from_id'];
                        $toId = (int) $range['to_id'];
                        $result = app(CustomerAssignmentService::class)->assign(
                            self::recordIdsInRange($fromId, $toId),
                            (int) $range['employee_id'],
                            CustomerAssignmentService::TARGET_AI_RECORD,
                            $assignedBy,
                        );

                        return [
                            'assigned' => $result['assigned'],
                            'text' => AiRecordSerialRanges::label(['from' => $fromId, 'to' => $toId])
                                .' → '.($employeeNames[(int) $range['employee_id']] ?? 'user').': '
                                .$result['assigned'].' assigned'
                                .($result['skipped'] ? ", {$result['skipped']} already assigned" : ''),
                        ];
                    })
                    ->all());

                $totalAssigned = array_sum(array_column($lines, 'assigned'));
                $body = implode('<br>', array_map(fn (array $line): string => e($line['text']), $lines));

                if ($totalAssigned === 0) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('No unassigned records in '.(count($lines) === 1 ? 'that range' : 'those ranges').'.<br>'.$body)
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Assigned')
                    ->body("{$totalAssigned} record(s) assigned.<br>{$body}")
                    ->success()
                    ->send();
            });
    }

    /**
     * From / To typed in the rows above the one $component sits in, keyed
     * by row number (1-based), so a row only offers what is still free.
     *
     * @return array<int, array{from: int, to: int}>
     */
    private static function rangesAboveRow(Get $get, Component $component): array
    {
        $rows = $get('../../ranges');
        $ownKey = (string) str((string) $component->getContainer()->getStatePath())->afterLast('.');
        $above = [];
        $rowNumber = 0;

        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            $rowNumber++;

            if ((string) $key === $ownKey) {
                break;
            }

            if (is_array($row) && ($range = self::typedRange($row['from_id'] ?? null, $row['to_id'] ?? null))) {
                $above[$rowNumber] = $range;
            }
        }

        return $above;
    }

    /**
     * The first row above whose range shares a S. No. with this row's.
     *
     * @return array{row: int, label: string}|null
     */
    private static function overlapWithRangesAbove(Get $get, Component $component): ?array
    {
        $own = self::typedRange($get('from_id'), $get('to_id'));

        if ($own === null) {
            return null;
        }

        foreach (self::rangesAboveRow($get, $component) as $rowNumber => $range) {
            if ($range['from'] <= $own['to'] && $own['from'] <= $range['to']) {
                return ['row' => $rowNumber, 'label' => AiRecordSerialRanges::label($range)];
            }
        }

        return null;
    }

    /**
     * @return array{from: int, to: int}|null
     */
    private static function typedRange(mixed $fromId, mixed $toId): ?array
    {
        if (! is_numeric($fromId) || ! is_numeric($toId) || (int) $fromId < 1 || (int) $toId < 1) {
            return null;
        }

        return ['from' => min((int) $fromId, (int) $toId), 'to' => max((int) $fromId, (int) $toId)];
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

    private static function rangeSummary(Get $get, Component $component): string
    {
        $range = self::typedRange($get('from_id'), $get('to_id'));

        if ($range === null) {
            return 'Enter both serial numbers to see how many records will be assigned.';
        }

        if ($clash = self::overlapWithRangesAbove($get, $component)) {
            return "Overlaps Range {$clash['row']} ({$clash['label']}). Change From / To so each record goes to one user only.";
        }

        $bounds = [$range['from'], $range['to']];
        $inRange = AiCustomerRecord::query()->whereBetween('id', $bounds)->count();

        if ($inRange === 0) {
            return 'No records in this range.';
        }

        $toAssign = AiCustomerRecord::query()->whereBetween('id', $bounds)->unassigned()->count();
        $alreadyAssigned = $inRange - $toAssign;

        return "{$inRange} record(s) in range: {$toAssign} will be assigned, {$alreadyAssigned} already assigned.";
    }

    private static function assignmentExistsSql(): string
    {
        return 'SELECT 1 FROM customer_assignments WHERE customer_assignments.ai_customer_record_id = ai_customer_records.id';
    }
}
