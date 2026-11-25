<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Actions\EmployeeDependencyStep;
use App\Filament\Actions\ReopenAction;
use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Widgets\TargetDataWarnings;
use App\Models\Employee;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeTransferService;
use App\Services\ReportingLineService;
use App\Support\EmployeeOptions;
use App\Support\ReportingTree;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListEmployees extends ListRecords
{
    use HasColumnHeaderFilters;

    /** All or part of one boss's team moves to another boss. */
    public const MODE_MOVE_TEAM = 'move_team';

    /** Person by person, each to their own destination. */
    public const MODE_FLEXIBLE = 'flexible_reassignment';

    protected static string $resource = EmployeeResource::class;

    /**
     * Admin only: who makes this month's targets wrong (TargetDataWarnings).
     */
    protected function getHeaderWidgets(): array
    {
        return [
            TargetDataWarnings::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('transferCluster')
                ->label('Transfer / Reassign Team')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->hasRole('Admin') === true)
                ->modalHeading('Transfer / Reassign Employees')
                ->modalDescription('Move a Team: all or part of anybody\'s team (a Team Leader\'s callers, a Manager\'s Team Leaders, a Cluster Manager\'s Managers) moves to another boss while that boss stays put. Flexible Reassignment: move any employees to anyone more senior — levels in between may be skipped, each row can have its own destination, and each chooses whether their team moves with them or stays behind. Everyone takes all their own customers and leads along.')
                ->modalSubmitActionLabel('Confirm Transfer')
                ->modalWidth('5xl')
                // Back from the hand-over step with the rows as they were.
                ->fillForm(fn (array $arguments): ?array => $arguments['batch'] ?? null)
                ->form([
                    Select::make('transfer_type')
                        ->label('Transfer Mode')
                        ->options([
                            self::MODE_MOVE_TEAM => 'Move a Team (whole or part, any level)',
                            self::MODE_FLEXIBLE => 'Flexible Reassignment (person by person)',
                        ])
                        ->default(self::MODE_FLEXIBLE)
                        ->native(false)
                        ->live()
                        ->required()
                        ->helperText('A Team Leader, Manager, Cluster Manager or Business Head can hand over their whole team or part of it with Move a Team.'),

                    Select::make('team_boss_id')
                        ->label('Whose team')
                        ->options(fn (): array => $this->teamOwnerOptions())
                        ->searchable()
                        ->live()
                        ->required(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM)
                        ->afterStateUpdated(function (Set $set): void {
                            $set('team_member_ids', []);
                            $set('team_target_id', null);
                        })
                        ->helperText('They stay where they are; only the people below move.'),

                    ToggleButtons::make('team_scope')
                        ->label('Who moves')
                        ->options([
                            EmployeeTransferService::TEAM_WHOLE => 'Whole team',
                            EmployeeTransferService::TEAM_PART => 'Only the people I tick',
                        ])
                        ->default(EmployeeTransferService::TEAM_WHOLE)
                        ->inline()
                        ->live()
                        ->required(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM && filled($get('team_boss_id')))
                        ->afterStateUpdated(fn (Set $set): mixed => $set('team_target_id', null)),

                    CheckboxList::make('team_member_ids')
                        ->label('Moving')
                        ->options(fn (Get $get): array => ($boss = $this->teamOwner($get)) ? app(EmployeeTransferService::class)->teamChecklist($boss)['options'] : [])
                        ->descriptions(fn (Get $get): array => ($boss = $this->teamOwner($get)) ? app(EmployeeTransferService::class)->teamChecklist($boss)['descriptions'] : [])
                        ->bulkToggleable()
                        ->searchable()
                        ->columns(2)
                        ->live()
                        ->required(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM && $get('team_scope') === EmployeeTransferService::TEAM_PART)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM && $get('team_scope') === EmployeeTransferService::TEAM_PART && filled($get('team_boss_id'))),

                    Select::make('team_target_id')
                        ->label('Move them to')
                        ->options(fn (Get $get): array => ($boss = $this->teamOwner($get))
                            ? app(EmployeeTransferService::class)->moveTeamTargetOptions($boss, $get('team_scope') === EmployeeTransferService::TEAM_PART
                                ? array_map('intval', (array) ($get('team_member_ids') ?? []))
                                : null)
                            : [])
                        ->searchable()
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === self::MODE_MOVE_TEAM && filled($get('team_boss_id')))
                        ->helperText('Anyone more senior than everybody moving; the levels in between can be skipped.'),

                    Repeater::make('assignments')
                        ->label('Flexible Reassignment')
                        ->visible(fn (Get $get): bool => $get('transfer_type') === self::MODE_FLEXIBLE)
                        ->required(fn (Get $get): bool => $get('transfer_type') === self::MODE_FLEXIBLE)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add Another Transfer')
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(function (array $state): ?string {
                            if (! isset($state['employee_id'])) {
                                return 'New Transfer';
                            }

                            $employee = Employee::find($state['employee_id']);

                            return $employee
                                ? "{$employee->emp_name} → ".($state['target_id'] ? (Employee::find($state['target_id'])?->emp_name ?? 'Destination') : 'Select destination')
                                : 'New Transfer';
                        })
                        ->schema([
                            Select::make('employee_id')
                                ->label('Employee to Move')
                                ->options(fn (): array => $this->flexibleSourceOptions())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->disableOptionWhen(function (string $value, Get $get): bool {
                                    $currentId = (int) $value;
                                    $allAssignments = $get('../../assignments') ?? [];

                                    return collect($allAssignments)
                                        ->pluck('employee_id')
                                        ->filter()
                                        ->map(fn ($id): int => (int) $id)
                                        ->filter(fn (int $id): bool => $id === $currentId)
                                        ->count() > 1;
                                })
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('target_id', null);
                                    $set('team', null);
                                })
                                ->helperText('All their own customers and leads go with them.'),

                            Select::make('target_id')
                                ->label('Move To')
                                ->options(function (Get $get): array {
                                    $employeeId = (int) ($get('employee_id') ?: 0);

                                    return $this->flexibleTargetOptions($employeeId);
                                })
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->live()
                                ->required()
                                ->disableOptionWhen(fn (string $value, Get $get): bool => (int) $value === (int) ($get('employee_id') ?: 0))
                                ->helperText(fn (Get $get): string => filled($get('employee_id'))
                                    ? 'Choose anyone more senior. The levels in between can be skipped.'
                                    : 'Select an employee first.'),

                            ToggleButtons::make('team')
                                ->label('Their team')
                                ->options([
                                    EmployeeTransferService::TEAM_WHOLE => 'Moves with them',
                                    EmployeeTransferService::TEAM_NONE => 'Stays behind',
                                ])
                                ->inline()
                                ->required(fn (Get $get): bool => $this->hasTeam($get('employee_id')))
                                ->visible(fn (Get $get): bool => $this->hasTeam($get('employee_id')))
                                ->helperText(fn (Get $get): string => $get('team') === EmployeeTransferService::TEAM_NONE
                                    ? 'Their team is given a new boss in the next step and keeps its own work.'
                                    : 'Everyone below them moves too — except anybody another row moves elsewhere, so add a row per person going somewhere else to split the team.')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),

                    DatePicker::make('effective_date')
                        ->label('Effective Date')
                        ->default(now()->toDateString())
                        ->maxDate(now())
                        ->native(false)
                        ->required(),

                    Textarea::make('remarks')
                        ->label('Remarks')
                        ->rows(3)
                        ->maxLength(2000)
                        ->placeholder('Optional reason / management instruction for this transfer.')
                        ->columnSpanFull(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    try {
                        if (($data['transfer_type'] ?? null) === self::MODE_MOVE_TEAM) {
                            $log = app(EmployeeTransferService::class)->moveTeam(
                                Employee::query()->findOrFail((int) $data['team_boss_id']),
                                ($data['team_scope'] ?? null) === EmployeeTransferService::TEAM_PART
                                    ? array_map('intval', (array) ($data['team_member_ids'] ?? []))
                                    : null,
                                (int) $data['team_target_id'],
                                $data['effective_date'] ?? today()->toDateString(),
                                $data['remarks'] ?? null,
                                (int) auth()->id(),
                            );
                        } else {
                            $transfers = app(EmployeeTransferService::class);
                            $blockers = $transfers->blockers($data['assignments'] ?? []);

                            if ($blockers !== []) {
                                $this->replaceMountedAction('clearTransferDependencies', [
                                    'employee' => array_key_first($blockers),
                                    'batch' => Arr::only($data, ['transfer_type', 'assignments', 'effective_date', 'remarks']),
                                ]);

                                return;
                            }

                            $log = $transfers->reassign(
                                assignments: $data['assignments'] ?? [],
                                performedBy: (int) auth()->id(),
                                effectiveDate: $data['effective_date'] ?? null,
                                remarks: $data['remarks'] ?? null,
                            );
                        }

                        Notification::make()
                            ->success()
                            ->title('Hierarchy transfer completed')
                            ->body("{$log->affected_count} employee(s) transferred successfully. Transfer Log #{$log->id}.")
                            ->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())
                            ->flatten()
                            ->first() ?: 'The transfer could not be completed.';

                        Notification::make()
                            ->danger()
                            ->title('Hierarchy transfer failed')
                            ->body($message)
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->danger()
                            ->title('Hierarchy transfer failed')
                            ->body('The transfer was rolled back because an unexpected error occurred. No partial hierarchy update was committed.')
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * The Flexible Reassignment's hand-over step, mounted in its place when
     * somebody being moved still has a team (EmployeeDependencyService::
     * SCOPE_MOVE). Once that person is clear it
     * moves on to the next one, and finally back to the transfer form with
     * the same rows, ready to confirm.
     */
    public function clearTransferDependenciesAction(): Action
    {
        $transfers = app(EmployeeTransferService::class);

        $scope = EmployeeDependencyService::SCOPE_MOVE;

        $employee = fn (array $arguments): Employee => once(fn (): Employee => Employee::query()->findOrFail((int) ($arguments['employee'] ?? 0)));

        $alsoMoving = fn (array $arguments): array => $transfers->alsoMovingIds($arguments['batch']['assignments'] ?? [], (int) ($arguments['employee'] ?? 0));

        return Action::make('clearTransferDependencies')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('warning')
            ->modalHeading(fn (array $arguments): string => EmployeeDependencyStep::heading($employee($arguments), $scope))
            ->modalDescription(fn (array $arguments): string => EmployeeDependencyStep::description(
                $employee($arguments),
                'Their customers and leads go with them. The transfer comes back for you to confirm once nobody is left reporting to them.',
                $alsoMoving($arguments),
                $scope,
            ))
            ->fillForm(fn (array $arguments): array => [
                'handover_date' => $arguments['batch']['effective_date'] ?? today()->toDateString(),
            ])
            ->schema(fn (array $arguments): array => EmployeeDependencyStep::schema($employee($arguments), $alsoMoving($arguments), $scope))
            ->modalSubmitActionLabel('Hand over & continue')
            ->action(function (array $arguments, array $data, Action $action) use ($transfers, $employee, $alsoMoving, $scope): void {
                $remaining = EmployeeDependencyStep::clear($employee($arguments), $data, $action, $alsoMoving($arguments), $scope);

                if ($remaining !== []) {
                    ReopenAction::for($action, $arguments);

                    return;
                }

                $blockers = $transfers->blockers($arguments['batch']['assignments'] ?? []);

                if ($blockers !== []) {
                    ReopenAction::for($action, [...$arguments, 'employee' => array_key_first($blockers)]);

                    return;
                }

                $this->replaceMountedAction('transferCluster', ['batch' => $arguments['batch']]);
            });
    }

    /**
     * Everybody on the rolls with somebody reporting to them — whose team
     * a "Move a Team" can move — grouped by position, most senior first.
     *
     * @return array<string, array<int, string>>
     */
    private function teamOwnerOptions(): array
    {
        $tree = ReportingTree::load();
        $positions = Employee::designationOptions();

        $ownerIds = array_filter(
            $tree->employeeIds(),
            fn (int $id): bool => ! $tree->isExited($id)
                && array_filter($tree->childIds($id), fn (int $childId): bool => ! $tree->isExited($childId)) !== [],
        );

        return Employee::query()
            ->whereIn('id', $ownerIds)
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id', 'designation'])
            ->sortByDesc(fn (Employee $employee): int => Employee::designationRank($employee->designation))
            ->groupBy(fn (Employee $employee): string => $positions[$employee->designation] ?? 'Other')
            ->map(fn ($group): array => $group
                ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
                ->all())
            ->all();
    }

    /**
     * The employee whose team the "Move a Team" form is moving.
     */
    private function teamOwner(Get $get): ?Employee
    {
        $bossId = (int) ($get('team_boss_id') ?: 0);

        return $bossId > 0 ? Employee::query()->find($bossId) : null;
    }

    /**
     * Whether somebody on the rolls reports straight to the employee.
     */
    private function hasTeam(mixed $employeeId): bool
    {
        if (blank($employeeId)) {
            return false;
        }

        $tree = ReportingTree::load();

        return array_filter($tree->childIds((int) $employeeId), fn (int $childId): bool => ! $tree->isExited($childId)) !== [];
    }

    /**
     * Everyone who reports to somebody and can therefore be moved.
     */
    private function flexibleSourceOptions(): array
    {
        $labels = Employee::designationOptions();

        return Employee::query()
            ->whereIn('designation', [
                Employee::DESIGNATION_CALLER,
                Employee::DESIGNATION_TEAM_LEADER,
                Employee::DESIGNATION_MANAGER,
                Employee::DESIGNATION_CLUSTER,
            ])
            ->where('exit_status', '!=', 'yes')
            ->orderBy('designation')
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => ($labels[$employee->designation] ?? 'Employee').": {$employee->emp_name} - ({$employee->emp_id})",
            ])
            ->all();
    }

    /**
     * Anyone more senior than the employee being moved, grouped by level.
     */
    private function flexibleTargetOptions(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }

        $employee = Employee::query()->find($employeeId);

        if (! $employee) {
            return [];
        }

        return app(ReportingLineService::class)->bossOptions($employee->designation, $employee->id);
    }
}
