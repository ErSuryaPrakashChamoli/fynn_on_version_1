<?php

namespace App\Filament\Exports;

use App\Filament\Actions\DataDownloadApproval;
use App\Models\Lead;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Every field of a lead, plus the names behind its ids (owner and their
 * reporting line, bank, converted customer file). Used by the Data
 * Download page; the export always needs the owner's authenticator code
 * (DataDownloadApproval).
 */
class LeadExporter extends Exporter
{
    protected static ?string $model = Lead::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Lead ID'),
            ExportColumn::make('customer_name')->label('Customer Name'),
            ExportColumn::make('mobile_no')->label('Mobile No'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('pan_number')->label('PAN Number'),
            ExportColumn::make('current_location')->label('Current Location'),
            ExportColumn::make('job_location')->label('Job Location'),
            ExportColumn::make('residence_location')->label('Residence Location'),
            ExportColumn::make('salary')->label('Salary'),
            ExportColumn::make('follow_up_date')->label('Follow-up Date'),
            ExportColumn::make('follow_up_type')->label('Follow-up Type'),
            ExportColumn::make('status')->label('Status'),
            ExportColumn::make('next_follow_up_date')->label('Next Follow-up Date'),
            ExportColumn::make('remarks')->label('Remarks'),
            ExportColumn::make('is_converted')
                ->label('Converted')
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No'),
            ExportColumn::make('converted_customer_id')->label('Converted Customer ID'),
            ExportColumn::make('convertedCustomer.application_no')->label('Converted Customer Application No'),
            ExportColumn::make('application_no')->label('Application No'),
            ExportColumn::make('bank_id')->label('Bank ID'),
            ExportColumn::make('bank.bank_name')->label('Bank'),
            ExportColumn::make('employee_id')->label('Owner Employee ID'),
            ExportColumn::make('employee.emp_id')->label('Owner EMP ID'),
            ExportColumn::make('employee.emp_name')->label('Owner Name'),
            ExportColumn::make('employee.superviser.emp_id')->label('Team Leader EMP ID'),
            ExportColumn::make('employee.superviser.emp_name')->label('Team Leader Name'),
            ExportColumn::make('employee.manager.emp_id')->label('Manager EMP ID'),
            ExportColumn::make('employee.manager.emp_name')->label('Manager Name'),
            ExportColumn::make('employee.clusterManager.emp_id')->label('Cluster Manager EMP ID'),
            ExportColumn::make('employee.clusterManager.emp_name')->label('Cluster Manager Name'),
            ExportColumn::make('employee.businessHead.emp_id')->label('Business Head EMP ID'),
            ExportColumn::make('employee.businessHead.emp_name')->label('Business Head Name'),
            ExportColumn::make('created_at')->label('Created At'),
            ExportColumn::make('updated_at')->label('Updated At'),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            DataDownloadApproval::codeField(),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'bank',
            'convertedCustomer',
            'employee.superviser',
            'employee.manager',
            'employee.clusterManager',
            'employee.businessHead',
        ]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your lead export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
