<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lifecycle')
                ->label('Employee Lifecycle')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->modalHeading(fn () => 'Employee Lifecycle — ' . $this->record->emp_name)
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function () {
                    $employee = $this->record->load([
                        'superviser',
                        'manager',
                        'clusterManager',
                    ]);

                    $histories = $employee->reportingHistories()
                        ->with([
                            'oldSupervisor',
                            'oldManager',
                            'oldCluster',
                            'newSupervisor',
                            'newManager',
                            'newCluster',
                            'updatedBy',
                        ])
                        ->orderBy('effective_date')
                        ->orderBy('id')
                        ->get();

                    return view('filament.employees.lifecycle', [
                        'employee' => $employee,
                        'histories' => $histories,
                    ]);
                }),

            EditAction::make(),
        ];
    }
}
