<?php

namespace App\Filament\Imports;

use App\Models\Employee;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

class EmployeeImporter extends Importer
{
    protected static ?string $model = Employee::class;

    /** The employee this row reports to, resolved from its employee IDs. */
    protected ?int $bossId = null;

    protected bool $recordExisted = false;

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
            // The Employee ID of whoever this employee reports to, at any
            // more senior level. Not a column: every reporting column is
            // derived from it — see ReportingLineService.
            ImportColumn::make('reports_to')
                ->label('Reports To (Employee ID)')
                ->rules(['nullable', 'string', 'max:255'])
                ->fillRecordUsing(fn (): null => null),
            // Older import files name the Team Leader / Manager instead;
            // the nearest one given is used as the boss.
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
        foreach (['superviser_id', 'manager_id'] as $column) {
            if (! empty($this->data[$column])) {
                $this->data[$column] = $this->employeeIdFor($this->data[$column]);
            }
        }

        $this->bossId = $this->employeeIdFor($this->data['reports_to'] ?? null)
            ?? (filled($this->data['superviser_id'] ?? null) ? (int) $this->data['superviser_id'] : null)
            ?? (filled($this->data['manager_id'] ?? null) ? (int) $this->data['manager_id'] : null);

        return Employee::firstOrNew([
            'emp_id' => $this->data['emp_id'],
        ]);
    }

    protected function beforeSave(): void
    {
        if (filled($this->data['reports_to'] ?? null) && $this->employeeIdFor($this->data['reports_to']) === null) {
            throw new RowImportFailedException("No employee with ID {$this->data['reports_to']} to report to.");
        }

        $this->recordExisted = $this->record->exists;

        // An update that names no boss keeps the one the employee has.
        if ($this->recordExisted && $this->bossId === null && blank($this->data['reports_to'] ?? null)) {
            $this->bossId = HierarchyHelper::directBossId($this->record);
        }

        $reportingLines = app(ReportingLineService::class);
        $designation = filled($this->record->designation) ? (int) $this->record->designation : null;
        $problem = $reportingLines->bossProblem($designation, $this->bossId, $this->record->id);

        if ($problem !== null) {
            throw new RowImportFailedException("{$this->record->emp_id}: {$problem}");
        }

        if ($this->recordExisted) {
            // Moved in afterSave() instead, so the change is recorded and
            // the team below follows.
            foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
                $this->record->{$column} = $this->record->getOriginal($column);
            }

            return;
        }

        // Filled before the insert, so the joining history row carries
        // the full reporting line.
        $this->record->forceFill($reportingLines->columnsUnder($this->bossId));
    }

    protected function afterSave(): void
    {
        if (! $this->recordExisted) {
            return;
        }

        app(ReportingLineService::class)->reportTo(
            $this->record,
            $this->bossId,
            today(),
            ReportingLineService::CHANGE_REPORTING,
            'Reporting line updated by employee import.',
            $this->import->user_id,
        );
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your employee import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    protected function beforeValidation(): void
    {
        // This logs the raw CSV row data right before Filament validates it
        logger('Importing Row Data:', $this->data);
    }

    private function employeeIdFor(mixed $empId): ?int
    {
        if (blank($empId)) {
            return null;
        }

        $id = Employee::query()->where('emp_id', $empId)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
