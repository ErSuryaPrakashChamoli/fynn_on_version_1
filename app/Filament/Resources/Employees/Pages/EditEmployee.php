<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected array $oldReporting = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {

        if (($data['exit_status'] ?? 'no') === 'no') {
            $data['exit_date'] = null;
        }
        $this->oldReporting = [
            'superviser_id' => $this->record->superviser_id,
            'manager_id' => $this->record->manager_id,
            'cluster_id' => $this->record->cluster_id,
            'reporting_date' => $this->record->reporting_date,
            'exit_status' => $this->record->exit_status,
            'exit_date' => $this->record->exit_date,
        ];

        return $data;
    }

    protected function afterSave(): void
    {
        $employee = $this->record;

        /*
        |--------------------------------------------------------------------------
        | REPORTING CHANGE
        |--------------------------------------------------------------------------
        */

        if (
            $this->oldReporting['superviser_id'] != $employee->superviser_id ||
            $this->oldReporting['manager_id'] != $employee->manager_id ||
            $this->oldReporting['cluster_id'] != $employee->cluster_id
        ) {
            $effectiveDate = $employee->reporting_date;

            // A reporting-hierarchy change moves an existing,
            // active employee — it must never reset reporting_date
            // merely because the admin didn't type a new one here,
            // since that would make AchievementCalculatorService's
            // "new joiner" worked-days rule wrongly treat them as
            // newly hired for the current month. $effectiveDate is
            // still computed (defaulting to today when the admin
            // didn't change it) and used below for the history
            // entries' own effective_date/effective_to — it is just
            // no longer persisted onto the employee record itself.
            if (
                blank($effectiveDate) ||
                (string) $effectiveDate === (string) $this->oldReporting['reporting_date']
            ) {
                $effectiveDate = now()->toDateString();
            }

            EmployeeReportingHistory::where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => $effectiveDate,
                    'updated_at' => now(),
                ]);

            EmployeeReportingHistory::create([
                'employee_id' => $employee->id,
                'old_superviser_id' => $this->oldReporting['superviser_id'],
                'old_manager_id' => $this->oldReporting['manager_id'],
                'old_cluster_id' => $this->oldReporting['cluster_id'],
                'new_superviser_id' => $employee->superviser_id,
                'new_manager_id' => $employee->manager_id,
                'new_cluster_id' => $employee->cluster_id,
                'effective_date' => $effectiveDate,
                'change_type' => 'reporting_change',
                'updated_by' => auth()->id(),
                'remarks' => 'Reporting hierarchy updated from Employee Edit.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EXIT
        |--------------------------------------------------------------------------
        */

        if (
            $this->oldReporting['exit_status'] != $employee->exit_status &&
            $employee->exit_status === 'yes'
        ) {
            $exitDate = $employee->exit_date ?? now()->toDateString();

            EmployeeReportingHistory::where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => $exitDate,
                    'updated_at' => now(),
                ]);

            EmployeeReportingHistory::create([
                'employee_id' => $employee->id,
                'old_superviser_id' => $employee->superviser_id,
                'old_manager_id' => $employee->manager_id,
                'old_cluster_id' => $employee->cluster_id,
                'new_superviser_id' => null,
                'new_manager_id' => null,
                'new_cluster_id' => null,
                'effective_date' => $exitDate,
                'change_type' => 'exit',
                'updated_by' => auth()->id(),
                'remarks' => 'Employee exited.',
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),

            Action::make('transferEmployee')
                ->label('Transfer Employee')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->fillForm(fn () => [

                    'current_superviser' => $this->record->superviser?->emp_name,

                    'current_manager' => $this->record->manager?->emp_name,

                    'current_cluster' => $this->record->clusterManager?->emp_name,

                    'effective_date' => now()->toDateString(),

                ])
                ->form([

                    TextInput::make('current_superviser')
                        ->label('Current Team Leader')
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('current_manager')
                        ->label('Current Manager')
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('current_cluster')
                        ->label('Current Cluster')
                        ->disabled()
                        ->dehydrated(false),

                    // Select::make('new_superviser_id')
                    //     ->label('New Team Leader')
                    //     ->options(
                    //         Employee::query()
                    //             ->where('designation', 'Team Leader')
                    //             ->orderBy('emp_name')
                    //             ->pluck('emp_name', 'id')
                    //     )
                    //     ->searchable()
                    //     ->live()
                    //     ->required(),

                    Select::make('new_superviser_id')
                        ->label('New Team Leader')
                        ->relationship(
                            'superviser',
                            'emp_name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                                ->where('exit_status', '!=', 'yes')
                        )
                        ->searchable()
                        ->live()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->required()
                        ->preload(),

                    DatePicker::make('effective_date')
                        ->required(),

                    Textarea::make('remarks')
                        ->rows(3),

                ])
                ->action(function (array $data) {

                    $employee = $this->record;

                    $newTL = Employee::findOrFail($data['new_superviser_id']);

                    $newManager = $newTL->manager;

                    $newCluster = $newTL->clusterManager;

                    /*
    |--------------------------------------------------------------------------
    | Close Previous Reporting History
    |--------------------------------------------------------------------------
    */

                    EmployeeReportingHistory::where('employee_id', $employee->id)
                        ->whereNull('effective_to')
                        ->update([
                            'effective_to' => $data['effective_date'],
                        ]);

                    /*
    |--------------------------------------------------------------------------
    | Update Employee
    |--------------------------------------------------------------------------
    */

                    $oldSupervisor = $employee->superviser_id;
                    $oldManager = $employee->manager_id;
                    $oldCluster = $employee->cluster_id;

                    // reporting_date is deliberately left untouched here: this transfer
                    // moves an existing, active employee — it must not make
                    // AchievementCalculatorService's "new joiner" worked-days rule treat
                    // them as newly hired for the transfer month. $data['effective_date']
                    // is still recorded below on the EmployeeReportingHistory entry.
                    $employee->update([

                        'superviser_id' => $newTL->id,

                        'manager_id' => $newManager?->id,

                        'cluster_id' => $newCluster?->id,

                    ]);

                    /*
    |--------------------------------------------------------------------------
    | Create Reporting History
    |--------------------------------------------------------------------------
    */

                    EmployeeReportingHistory::create([

                        'employee_id' => $employee->id,

                        'old_superviser_id' => $oldSupervisor,

                        'old_manager_id' => $oldManager,

                        'old_cluster_id' => $oldCluster,

                        'new_superviser_id' => $newTL->id,

                        'new_manager_id' => $newManager?->id,

                        'new_cluster_id' => $newCluster?->id,

                        'effective_date' => $data['effective_date'],

                        'effective_to' => null,

                        'change_type' => 'transfer',

                        'updated_by' => auth()->id(),

                        'remarks' => $data['remarks'],

                    ]);

                    Notification::make()
                        ->title('Employee transferred successfully.')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'superviser_id',
                        'manager_id',
                        'cluster_id',
                    ]);

                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
