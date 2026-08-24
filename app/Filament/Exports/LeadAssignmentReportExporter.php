<?php

namespace App\Filament\Exports;

use App\Filament\Resources\LeadAssignmentReports\Tables\LeadAssignmentReportsTable;
use App\Models\Employee;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class LeadAssignmentReportExporter extends Exporter
{
    protected static ?string $model = Employee::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('emp_id')->label('Employee ID'),
            ExportColumn::make('emp_name')->label('User'),
            ExportColumn::make('designation')
                ->label('Role')
                ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state),
            ExportColumn::make('assigned_count')->label('Assigned'),
            ExportColumn::make('opened_count')->label('Opened'),
            ExportColumn::make('contacted_count')->label('Contacted'),
            ExportColumn::make('eligible_count')->label('Eligible'),
            ExportColumn::make('not_eligible_count')->label('Not Eligible'),
            ExportColumn::make('sfl_count')->label('SFL'),
            ExportColumn::make('underwriting_count')->label('Underwriting'),
            ExportColumn::make('approved_count')->label('Approved'),
            ExportColumn::make('disbursed_count')->label('Disbursed'),
            ExportColumn::make('completed_count')->label('Completed'),
            ExportColumn::make('carry_forward_count')->label('Carry Forward'),
            ExportColumn::make('dropped_count')->label('Dropped'),
            ExportColumn::make('not_approved_count')->label('Not Approved'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your lead assignment report has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->withCount(LeadAssignmentReportsTable::funnelWithCount());
    }
}
