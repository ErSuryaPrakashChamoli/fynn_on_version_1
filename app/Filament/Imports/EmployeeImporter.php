<?php

namespace App\Filament\Imports;

use App\Models\Employee;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

class EmployeeImporter extends Importer
{
    protected static ?string $model = Employee::class;

    public static function getColumns(): array
    {
        return [

            ImportColumn::make('emp_id')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                    Rule::unique('employees', 'emp_id'),
                ]),

            ImportColumn::make('emp_name')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                    Rule::unique('employees', 'email'),
                ]),
            ImportColumn::make('designation')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('doj')
                ->rules(['date']),
            ImportColumn::make('reporting_date')
                ->rules(['date']),
            ImportColumn::make('superviser_id')
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('manager_id')
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('cost_center')
                ->rules(['max:255']),
            ImportColumn::make('unit_name')
                ->rules(['max:255']),
        ];
    }

    public function resolveRecord(): Employee
    {


        if (! empty($this->data['superviser_id'])) {
            $this->data['superviser_id'] = Employee::where(
                'emp_id',
                $this->data['superviser_id']
            )->value('id');
        }

        if (! empty($this->data['manager_id'])) {
            $this->data['manager_id'] = Employee::where(
                'emp_id',
                $this->data['manager_id']
            )->value('id');
        }

        return Employee::firstOrNew([
            'emp_id' => $this->data['emp_id'],
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your employee import has completed and ' . Number::format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }

    protected function beforeValidation(): void
    {
        // This logs the raw CSV row data right before Filament validates it
        logger('Importing Row Data:', $this->data);
    }
}
