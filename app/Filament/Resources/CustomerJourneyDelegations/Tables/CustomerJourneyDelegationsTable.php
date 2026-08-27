<?php

namespace App\Filament\Resources\CustomerJourneyDelegations\Tables;

use App\Enums\ContinuityCoverageType;
use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Services\Journey\CustomerJourneyDelegationService;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerJourneyDelegationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('delegatingManager.emp_name')
                    ->label('Original Employee')
                    ->searchable()
                    ->description(fn (CustomerJourneyDelegation $record): ?string => Employee::designationOptions()[$record->delegatingManager?->designation] ?? null),

                TextColumn::make('actingManager.emp_name')
                    ->label('Backup Employee')
                    ->searchable()
                    ->description(fn (CustomerJourneyDelegation $record): ?string => Employee::designationOptions()[$record->actingManager?->designation] ?? null),

                TextColumn::make('coverage_type')
                    ->label('Coverage')
                    ->badge()
                    ->formatStateUsing(fn (ContinuityCoverageType $state): string => $state->label()),

                TextColumn::make('access_type')
                    ->label('Access Type')
                    ->badge()
                    ->formatStateUsing(fn (JourneyAccessType $state): string => $state->label())
                    ->color(fn (JourneyAccessType $state): string => match ($state) {
                        JourneyAccessType::EmergencyTakeover => 'danger',
                        JourneyAccessType::AdminOrganisationWideHandover => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('modules')
                    ->label('Modules')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state)
                        ? collect($state)->map(fn (string $value) => JourneyModule::tryFrom($value)?->label() ?? $value)->implode(', ')
                        : $state),

                TextColumn::make('start_at')
                    ->dateTime('d M Y h:i A')
                    ->label('Start'),

                TextColumn::make('end_at')
                    ->dateTime('d M Y h:i A')
                    ->label('End'),

                TextColumn::make('display_status')
                    ->label('Status')
                    ->state(fn (CustomerJourneyDelegation $record): string => $record->displayStatus())
                    ->badge()
                    ->colors([
                        'gray' => 'Pending',
                        'info' => 'Upcoming',
                        'success' => 'Active',
                        'danger' => 'Expired',
                        'warning' => 'Cancelled',
                    ]),

                TextColumn::make('reason')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('active')
                    ->label('Currently active only')
                    ->query(fn (Builder $query): Builder => $query->activeAt(now())),

                SelectFilter::make('access_type')
                    ->options(collect(JourneyAccessType::cases())->mapWithKeys(fn (JourneyAccessType $t): array => [$t->value => $t->label()])->all()),

                SelectFilter::make('coverage_type')
                    ->options(ContinuityCoverageType::options()),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(function (CustomerJourneyDelegation $record): bool {
                        $user = auth()->user();

                        if (! in_array($record->status, [CustomerJourneyDelegation::STATUS_PENDING, CustomerJourneyDelegation::STATUS_ACTIVE], true)) {
                            return false;
                        }

                        return $user->hasRole('Admin') || $user->employee?->id === $record->delegating_manager_id;
                    })
                    ->form([
                        Textarea::make('cancellation_reason')
                            ->label('Cancellation reason')
                            ->rows(2),
                    ])
                    ->action(function (CustomerJourneyDelegation $record, array $data): void {
                        try {
                            app(CustomerJourneyDelegationService::class)->cancel(
                                $record,
                                (int) auth()->id(),
                                $data['cancellation_reason'] ?? null,
                            );

                            Notification::make()->success()->title('Continuity rule cancelled')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not cancel continuity rule')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                Action::make('createBackup')
                    ->label('Create Backup')
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalHeading('Assign Team Continuity / Backup Access')
                    ->modalDescription('Grants an eligible backup temporary operational access for an employee\'s customers/leads — existing cases, new cases entering their scope, or both. Ownership never changes; access is automatically revoked at the end date/time.')
                    ->modalWidth('2xl')
                    ->form(fn (): array => self::form(emergency: false))
                    ->action(fn (array $data) => self::handleCreate($data)),

                Action::make('emergencyAssignment')
                    ->label('Emergency Assignment')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (): bool => auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']))
                    ->modalHeading('Emergency Continuity Assignment')
                    ->modalDescription('Immediate, effective-now coverage for an unexpectedly unavailable employee. Covers the configured scope without reassigning customers one by one.')
                    ->modalWidth('2xl')
                    ->form(fn (): array => self::form(emergency: true))
                    ->action(fn (array $data) => self::handleCreate($data, emergency: true)),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function form(bool $emergency): array
    {
        return [
            Select::make('delegating_manager_id')
                ->label('Original Employee')
                ->options(fn (): array => self::originalEmployeeOptions())
                ->default(fn (): ?int => auth()->user()->employee?->id)
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('acting_manager_id', null))
                ->required(),

            Toggle::make('is_admin_override')
                ->label('Organisation-wide Handover')
                ->helperText('Admin only — lets you pick a backup from anywhere in the organisation, bypassing the normal hierarchy restriction. Never changes the organisation hierarchy or permanent ownership.')
                ->visible(fn (): bool => auth()->user()->hasRole('Admin'))
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('acting_manager_id', null))
                ->default(false),

            // getSearchResultsUsing() is called fresh on every keystroke —
            // confirmed correct server-side (including via a raw per-field
            // Livewire ->set(), the same lifecycle a real toggle click
            // triggers, not just the bulk testing helper). What it can't
            // guard against is the BROWSER WIDGET reusing its own cached
            // result set for an unchanged search term ("" on reopen)
            // without re-querying — some searchable-select JS widgets key
            // their cache purely by search string, blind to a sibling field
            // changing server-side eligibility for that same string. ->key()
            // closes that gap: whenever Original Employee or the toggle
            // changes, Livewire treats this as a brand-new element and the
            // widget is destroyed and recreated from scratch, so there is no
            // JS-level cache left to reuse.
            Select::make('acting_manager_id')
                ->label('Backup Employee')
                ->key(fn (Get $get): string => 'acting_manager_id-'.($get('delegating_manager_id') ?: 'none').'-'.($get('is_admin_override') ? '1' : '0'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, Get $get): array => self::searchBackupEmployees(
                    $search,
                    (int) ($get('delegating_manager_id') ?: 0),
                    (bool) $get('is_admin_override'),
                ))
                ->getOptionLabelUsing(fn ($value): ?string => self::employeeLabel(Employee::find($value)))
                ->live()
                ->required()
                ->helperText('Type to search. A backup may be senior or junior to the original employee, as long as they are within that employee\'s own hierarchy branch — unless Organisation-wide Handover is enabled.'),

            Select::make('coverage_type')
                ->label('Coverage')
                ->options(ContinuityCoverageType::options())
                ->default(ContinuityCoverageType::ExistingAndNew->value)
                ->native(false)
                ->required()
                ->helperText('Existing = cases already assigned before this rule starts. New = cases that would normally enter the original employee\'s scope while this rule is active.'),

            CheckboxList::make('modules')
                ->label('Covered Modules')
                ->options(JourneyModule::options())
                ->default(array_column(JourneyModule::cases(), 'value'))
                ->required()
                ->columns(2),

            DateTimePicker::make('start_at')
                ->label('Start')
                ->default(now())
                ->disabled($emergency)
                ->dehydrated()
                ->native(false)
                ->required(),

            DateTimePicker::make('end_at')
                ->label('End')
                ->default(fn (): Carbon => now()->addDay())
                ->native(false)
                ->required(),

            Textarea::make('reason')
                ->label('Reason')
                ->rows(3)
                ->required(),
        ];
    }

    private static function handleCreate(array $data, bool $emergency = false): void
    {
        if ($emergency) {
            $data['start_at'] = now();
            $data['access_type'] = JourneyAccessType::EmergencyTakeover->value;
        }

        if (! empty($data['is_admin_override'])) {
            $data['access_type'] = JourneyAccessType::AdminOrganisationWideHandover->value;
        }

        try {
            app(CustomerJourneyDelegationService::class)->create($data, auth()->user());

            Notification::make()->success()->title($emergency ? 'Emergency continuity activated' : 'Continuity backup created')->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Could not create continuity rule')
                ->body(collect($exception->errors())->flatten()->first())
                ->persistent()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Could not create continuity rule')
                ->body('An unexpected error occurred. No continuity rule was created.')
                ->send();
        }
    }

    /**
     * Section 12/15: a normal user may only nominate themselves or an
     * employee within their own hierarchy as the "original" (covered)
     * employee. Admin sees everyone.
     */
    private static function originalEmployeeOptions(): array
    {
        $user = auth()->user();

        $query = Employee::query()->where('exit_status', '!=', 'yes');

        if (! $user->hasRole('Admin') && ! $user->hasRole('Business Head')) {
            $employee = $user->employee;

            if (! $employee) {
                return [];
            }

            $query->whereIn('id', HierarchyHelper::subordinateIds($employee));
        }

        return $query->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $e): array => [$e->id => self::employeeLabel($e)])
            ->all();
    }

    /**
     * Section 12/13: the backup pool is the ORIGINAL employee's own branch
     * (their up-chain of superiors + their subordinate tree) — allowing a
     * senior-or-junior backup while blocking an arbitrary cross-cluster
     * pick. Admin with the Organisation-wide toggle sees every active
     * employee instead. Called fresh on every keystroke via
     * getSearchResultsUsing() (see form()) rather than a static options()
     * array, so it always reflects the CURRENT toggle/Original Employee —
     * there's nothing for a browser widget to cache stale.
     */
    private static function searchBackupEmployees(string $search, int $originalId, bool $adminOverride): array
    {
        $query = Employee::query()->where('exit_status', '!=', 'yes');

        if ($adminOverride && auth()->user()->hasRole('Admin')) {
            $query->when($originalId > 0, fn ($q) => $q->where('id', '!=', $originalId));
        } elseif ($originalId > 0) {
            $original = Employee::find($originalId);

            if (! $original) {
                return [];
            }

            $query->whereIn('id', HierarchyHelper::employeeHierarchyIds($original))
                ->where('id', '!=', $originalId);
        } else {
            return [];
        }

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('emp_name', 'like', "%{$search}%")
                    ->orWhere('emp_id', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('emp_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Employee $e): array => [$e->id => self::employeeLabel($e)])
            ->all();
    }

    private static function employeeLabel(?Employee $employee): ?string
    {
        if (! $employee) {
            return null;
        }

        return "{$employee->emp_name} ({$employee->emp_id}) — ".(Employee::designationOptions()[$employee->designation] ?? 'Unknown');
    }
}
