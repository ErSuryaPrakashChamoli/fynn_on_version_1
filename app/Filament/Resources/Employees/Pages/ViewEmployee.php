<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Actions\ChangeEmployeePositionAction;
use App\Filament\Actions\CorrectPositionChangeDateAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Reports To is not a column — it is read off the reporting tree, as on
     * the Edit page, or the field would show empty for everybody.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['reports_to'] = HierarchyHelper::directBossId($this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lifecycle')
                ->label('Employee Lifecycle')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->modalHeading(fn () => 'Employee Lifecycle — '.$this->record->emp_name)
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function () {
                    $employee = $this->record->load([
                        'superviser',
                        'manager',
                        'clusterManager',
                        'businessHead',
                    ]);

                    $histories = $employee->reportingHistories()
                        ->with([
                            'oldSupervisor',
                            'oldManager',
                            'oldCluster',
                            'newSupervisor',
                            'newManager',
                            'newCluster',
                            'oldBusinessHead',
                            'newBusinessHead',
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

            ChangeEmployeePositionAction::make(),
            CorrectPositionChangeDateAction::make(),
            EditAction::make(),
        ];
    }
}
