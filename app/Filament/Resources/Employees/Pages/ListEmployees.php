<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\HierarchyReassignmentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
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
                ->label('Transfer Cluster')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->hasRole('Admin') === true)
                ->modalHeading('Transfer Employees Between Cluster Managers')
                ->modalDescription('This operation changes cluster assignment only. Existing Manager and Team Leader reporting relationships are preserved.')
                ->modalSubmitActionLabel('Transfer Employees')
                ->modalWidth('4xl')
                ->form([
                    Select::make('source_cluster_manager_id')
                        ->label('Source Cluster Manager')
                        ->options(fn (): array => $this->clusterManagerOptions())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('target_cluster_manager_id', null);
                            $set('selected_employee_ids', []);
                        })
                        ->helperText('Only active Cluster Managers are available.'),

                    Select::make('target_cluster_manager_id')
                        ->label('Target / Destination Cluster Manager')
                        ->options(fn (Get $get): array => $this->clusterManagerOptions(
                            exceptId: (int) ($get('source_cluster_manager_id') ?: 0),
                        ))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('The destination must be an active Cluster Manager and cannot be the source.'),

                    Select::make('transfer_type')
                        ->label('Transfer Scope')
                        ->options([
                            'full_cluster' => 'Full Cluster Transfer',
                            'selective' => 'Selective Transfer',
                        ])
                        ->default('full_cluster')
                        ->native(false)
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if ($state !== 'selective') {
                                $set('selected_employee_ids', []);
                            }
                        })
                        ->helperText('Full transfers every active employee assigned to the source cluster. Selective transfers selected Manager/Team Leader branches.'),

                    Select::make('selected_employee_ids')
                        ->label('Managers / Team Leaders to Transfer')
                        ->options(fn (Get $get): array => $this->selectableHierarchyOptions(
                            (int) ($get('source_cluster_manager_id') ?: 0),
                        ))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('transfer_type') === 'selective')
                        ->required(fn (Get $get): bool => $get('transfer_type') === 'selective')
                        ->helperText('Selecting a Manager transfers the Manager, Team Leaders and AROs below that Manager. Selecting a Team Leader transfers that Team Leader and its AROs.'),

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
                        ->placeholder('Optional reason / management instruction for this transfer.'),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    try {
                        $log = app(HierarchyReassignmentService::class)->transfer(
                            data: $data,
                            performedBy: (int) auth()->id(),
                        );

                        Notification::make()
                            ->success()
                            ->title('Cluster transfer completed')
                            ->body("{$log->affected_count} employee(s) transferred successfully. Transfer Log #{$log->id}.")
                            ->send();
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())
                            ->flatten()
                            ->first() ?: 'The transfer could not be completed.';

                        Notification::make()
                            ->danger()
                            ->title('Cluster transfer failed')
                            ->body($message)
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->danger()
                            ->title('Cluster transfer failed')
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
}
