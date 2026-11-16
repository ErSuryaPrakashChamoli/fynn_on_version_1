<?php

namespace App\Filament\Exports;

use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Resources\AssignedLeads\Tables\AssignedLeadsTable;
use App\Models\CustomerAssignment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * The Assigned Leads listing as a sheet: each lead's prospect, who holds it,
 * who handed it over and when, how often it was opened and moved, and where
 * the chase stands (latest follow-up status and remarks). Lead data, so the
 * export is Admin only and needs the owner's authenticator code
 * (DataDownloadApproval), like "Export Customer Data".
 */
class AssignedLeadExporter extends Exporter
{
    protected static ?string $model = CustomerAssignment::class;

    private const DATE_TIME_FORMAT = 'd M Y h:i A';

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('template_name')
                ->label('Template')
                ->state(fn (CustomerAssignment $record): ?string => $record->template_name),
            ExportColumn::make('prospect_name')
                ->label('Prospect Name')
                ->state(fn (CustomerAssignment $record): string => $record->display_name),
            ExportColumn::make('mobile_number')
                ->label('Mobile Number')
                ->state(fn (CustomerAssignment $record): ?string => $record->prospectValue('mobile_number')),
            ExportColumn::make('source')
                ->label('Source')
                ->state(fn (CustomerAssignment $record): string => $record->source_label),
            ExportColumn::make('employee.emp_name')->label('Case Owner'),
            ExportColumn::make('employee.emp_id')->label('Case Owner Emp ID'),
            ExportColumn::make('assignedBy.emp_name')->label('Assigned By'),
            ExportColumn::make('created_at')
                ->label('Assigned On')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
            ExportColumn::make('open_status')
                ->label('Status')
                ->state(fn (CustomerAssignment $record): string => $record->status),
            ExportColumn::make('opens_count')->label('Opens'),
            ExportColumn::make('last_opened_at')
                ->label('Last Opened')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
            ExportColumn::make('follow_up_status')
                ->label('Follow-Up Status')
                ->state(fn (CustomerAssignment $record): string => $record->latest_follow_up_status ?? 'Pending'),
            ExportColumn::make('latest_remarks')
                ->label('Remarks')
                ->state(fn (CustomerAssignment $record): ?string => $record->latest_follow_up_remarks),
            ExportColumn::make('last_follow_up_on')
                ->label('Last Follow-Up On')
                ->state(fn (CustomerAssignment $record): ?string => self::formatDateTime($record->latest_follow_up_at)),
            ExportColumn::make('next_follow_up_on')
                ->label('Next Follow-Up')
                ->state(fn (CustomerAssignment $record): ?string => self::formatDateTime($record->latest_next_follow_up_date)),
            ExportColumn::make('journey')
                ->label('Journey')
                ->state(fn (CustomerAssignment $record): ?string => filled($record->customer?->journey_status)
                    ? AssignedLeadsTable::journeyLabel($record->customer->journey_status)
                    : null),
            ExportColumn::make('reassign_count')->label('Reassigned'),
            ExportColumn::make('last_reassigned_at')
                ->label('Last Reassigned On')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
            ExportColumn::make('converted_at')
                ->label('Converted On')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            DataDownloadApproval::codeField(),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your assigned leads export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    /**
     * Reads the latest follow-up per lead as subqueries (AI-record
     * follow-ups until conversion, customer follow-ups after), so the sheet
     * is built without a query per row.
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with([
                'customer',
                'aiCustomerRecord.schema',
                'template',
                'employee',
                'assignedBy',
            ])
            ->addSelect([
                'latest_follow_up_status' => CustomerAssignment::latestFollowUpValueQuery('status'),
                'latest_follow_up_remarks' => CustomerAssignment::latestFollowUpValueQuery('remarks'),
                'latest_follow_up_at' => CustomerAssignment::latestFollowUpValueQuery('created_at'),
                'latest_next_follow_up_date' => CustomerAssignment::latestFollowUpValueQuery('next_follow_up_date'),
            ]);
    }

    private static function formatDateTime(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->format(self::DATE_TIME_FORMAT) : null;
    }
}
