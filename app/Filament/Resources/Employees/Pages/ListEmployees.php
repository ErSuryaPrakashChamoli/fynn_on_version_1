<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\HierarchyReassignmentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

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
                ->modalDescription('Use Full Cluster for a complete cluster move. Use Flexible Reassignment to move individual Callers to any Team Leader and Team Leaders to any Manager. Each row can have a different destination.')
                ->modalSubmitActionLabel('Confirm Transfer')
                ->modalWidth('5xl')
                ->form([
                    Select::make('transfer_type')
                        ->label('Transfer Mode')
                        ->options([
                            'full_cluster' => 'Full Cluster Transfer',
                            'flexible_reassignment' => 'Flexible Reassignment',
                        ])
                        ->default('flexible_reassignment')
                        ->native(false)
                        ->live()
                        ->required()
                        ->helperText('Recommended: Flexible Reassignment for splitting a Manager team across different Managers / Team Leaders.'),

                    Select::make('source_cluster_manager_id')
                        ->label('Source Cluster Manager')
                        ->options(fn (): array => $this->clusterManagerOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(fn (Get $get): bool => $get('transfer_type') === 'full_cluster')
                        ->visible(fn (Get $get): bool => $get('transfer_type') === 'full_cluster')
                        ->afterStateUpdated(function (Set $set): void {
                            $set('target_cluster_manager_id', null);
                            $set('selected_employee_ids', []);
                        }),

                    Select::make('target_cluster_manager_id')
                        ->label('Target / Destination Cluster Manager')
                        ->options(fn (Get $get): array => $this->clusterManagerOptions(
                            exceptId: (int) ($get('source_cluster_manager_id') ?: 0),
                        ))
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('transfer_type') === 'full_cluster')
                        ->visible(fn (Get $get): bool => $get('transfer_type') === 'full_cluster'),

                    Select::make('selected_employee_ids')
                        ->label('Managers / Team Leaders to Transfer')
                        ->options(fn (Get $get): array => $this->selectableHierarchyOptions(
                            (int) ($get('source_cluster_manager_id') ?: 0),
                        ))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === 'full_cluster')
                        ->dehydrated(false),

                    Repeater::make('assignments')
                        ->label('Flexible Reassignment')
                        ->visible(fn (Get $get): bool => $get('transfer_type') === 'flexible_reassignment')
                        ->required(fn (Get $get): bool => $get('transfer_type') === 'flexible_reassignment')
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
                                ? "{$employee->emp_name} → " . ($state['target_id'] ? (Employee::find($state['target_id'])?->emp_name ?? 'Destination') : 'Select destination')
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
                                ->afterStateUpdated(fn (Set $set): mixed => $set('target_id', null))
                                ->helperText('Caller = move to a Team Leader. Team Leader = move to a Manager.'),

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
                                ->helperText(function (Get $get): string {
                                    $employee = Employee::find((int) ($get('employee_id') ?: 0));

                                    return match ($employee?->designation) {
                                        Employee::DESIGNATION_CALLER => 'Select the Team Leader who will become this Caller\'s new supervisor.',
                                        Employee::DESIGNATION_TEAM_LEADER => 'Select the Manager who will become this Team Leader\'s new manager.',
                                        default => 'Select an employee first.',
                                    };
                                }),
                        ])
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
                        $service = app(HierarchyReassignmentService::class);

                        if (($data['transfer_type'] ?? null) === 'flexible_reassignment') {
                            $log = $service->reassign(
                                assignments: $data['assignments'] ?? [],
                                performedBy: (int) auth()->id(),
                                effectiveDate: $data['effective_date'] ?? null,
                                remarks: $data['remarks'] ?? null,
                            );
                        } else {
                            $log = $service->transfer(
                                data: $data,
                                performedBy: (int) auth()->id(),
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

    private function clusterManagerOptions(int $exceptId = 0): array
    {
        return Employee::query()
            ->where('designation', Employee::DESIGNATION_CLUSTER)
            ->where('exit_status', '!=', 'yes')
            ->when($exceptId > 0, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => "{$employee->emp_name} - ({$employee->emp_id})",
            ])
            ->all();
    }

    private function selectableHierarchyOptions(int $sourceId): array
    {
        if ($sourceId <= 0) {
            return [];
        }

        $managers = Employee::query()
            ->where('cluster_id', $sourceId)
            ->where('designation', Employee::DESIGNATION_MANAGER)
            ->where('exit_status', '!=', 'yes')
            ->orderBy('emp_name')
            ->get();

        $teamLeaders = Employee::query()
            ->where('cluster_id', $sourceId)
            ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
            ->where('exit_status', '!=', 'yes')
            ->orderBy('emp_name')
            ->get();

        $options = [];

        foreach ($managers as $manager) {
            $options[(string) $manager->id] = "Manager: {$manager->emp_name} - ({$manager->emp_id})";
        }

        foreach ($teamLeaders as $teamLeader) {
            $options[(string) $teamLeader->id] = "Team Leader / ARO Group: {$teamLeader->emp_name} - ({$teamLeader->emp_id})";
        }

        return $options;
    }

    private function flexibleSourceOptions(): array
    {
        return Employee::query()
            ->whereIn('designation', [
                Employee::DESIGNATION_CALLER,
                Employee::DESIGNATION_TEAM_LEADER,
            ])
            ->where('exit_status', '!=', 'yes')
            ->orderBy('designation')
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(function (Employee $employee): array {
                $type = $employee->designation === Employee::DESIGNATION_CALLER
                    ? 'Caller'
                    : 'Team Leader';

                return [
                    $employee->id => "{$type}: {$employee->emp_name} - ({$employee->emp_id})",
                ];
            })
            ->all();
    }

    private function flexibleTargetOptions(int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }

        $employee = Employee::query()->find($employeeId);

        if (! $employee) {
            return [];
        }

        $targetDesignation = match ($employee->designation) {
            Employee::DESIGNATION_CALLER => Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_TEAM_LEADER => Employee::DESIGNATION_MANAGER,
            default => null,
        };

        if ($targetDesignation === null) {
            return [];
        }

        return Employee::query()
            ->where('designation', $targetDesignation)
            ->where('exit_status', '!=', 'yes')
            ->where('id', '!=', $employeeId)
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $target): array => [
                $target->id => ($targetDesignation === Employee::DESIGNATION_TEAM_LEADER ? 'Team Leader' : 'Manager') . ": {$target->emp_name} - ({$target->emp_id})",
            ])
            ->all();
    }
}
