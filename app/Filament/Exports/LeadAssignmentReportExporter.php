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
            ExportColumn::make('untouched_count')->label('Not Touched'),
            ExportColumn::make('opened_count')->label('Opened'),
            ExportColumn::make('followed_up_count')->label('Followed Up'),
            ExportColumn::make('converted_count')->label('Converted'),
            ExportColumn::make('overdue_count')->label('Overdue Follow-Ups'),
            ExportColumn::make('reassigned_out_count')->label('Reassigned Away'),
            ExportColumn::make('interested_count')->label('Interested'),
            ExportColumn::make('not_interested_count')->label('Not Interested'),
            ExportColumn::make('busy_count')->label('Busy'),
            ExportColumn::make('no_response_count')->label('No Response'),
            ExportColumn::make('not_eligible_remark_count')->label('Not Eligible (Remark)'),
            ExportColumn::make('other_bank_count')->label('Eligible for Other Bank'),
            ExportColumn::make('pending_count')->label('Pending'),
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
            ExportColumn::make('last_activity_at')->label('Last Follow-Up'),
            ExportColumn::make('work_status')
                ->label('Work Status')
                ->state(fn (Employee $record): string => LeadAssignmentReportsTable::workStatus($record)),
            ExportColumn::make('suggested_action')
                ->label('Suggested Action')
                ->state(fn (Employee $record): string => LeadAssignmentReportsTable::suggestedAction($record)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your lead assignment report has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    /**
     * The table query handed to the export already carries every count,
     * scoped by the filters that were active when it was downloaded.
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query;
    }
}
