<?php

namespace App\Filament\Exports;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class EmployeeHierarchyExporter extends Exporter
{
    protected static ?string $model = Employee::class;

    /**
     * Team Leader / Manager / Cluster Manager targets are expensive to
     * compute (they sum their whole team's targets) and the same person
     * is reused across many employee rows, so memoize by employee ID.
     *
     * @var array<int, float>
     */
    protected static array $targetCache = [];

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('emp_id')
                ->label('Employee ID'),

            ExportColumn::make('emp_name')
                ->label('Employee Name'),

            ExportColumn::make('designation')
                ->label('Designation')
                ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state),

            ExportColumn::make('monthly_target')
                ->label('Monthly Target')
                ->state(fn (Employee $record) => static::targetFor($record)),

            ExportColumn::make('exit_status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state === 'yes' ? 'Exited' : 'Active'),

            ExportColumn::make('superviser.emp_name')
                ->label('Team Leader'),

            ExportColumn::make('team_leader_target')
                ->label('Team Leader Target (Current Month)')
                ->state(fn (Employee $record) => static::targetFor($record->superviser)),

            ExportColumn::make('manager.emp_name')
                ->label('Manager'),

            ExportColumn::make('manager_target')
                ->label('Manager Target (Current Month)')
                ->state(fn (Employee $record) => static::targetFor($record->manager)),

            ExportColumn::make('clusterManager.emp_name')
                ->label('Cluster Manager'),

            ExportColumn::make('cluster_manager_target')
                ->label('Cluster Manager Target (Current Month)')
                ->state(fn (Employee $record) => static::targetFor($record->clusterManager)),
        ];
    }

    protected static function targetFor(?Employee $employee): ?float
    {
        if (! $employee) {
            return null;
        }

        return static::$targetCache[$employee->id] ??= app(AchievementCalculatorService::class)
            ->getTarget($employee);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your employee hierarchy export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with(['superviser', 'manager', 'clusterManager'])
            ->orderBy('emp_name');
    }
}
