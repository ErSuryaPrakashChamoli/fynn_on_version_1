<?php

namespace App\Filament\Exports;

use App\Models\Customer;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;
use Illuminate\Database\Eloquent\Builder;

class CustomerExporter extends Exporter
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('customer_name'),
            ExportColumn::make('mobile_no'),
            ExportColumn::make('email'),
            ExportColumn::make('pan_number'),
            ExportColumn::make('job_location'),
            ExportColumn::make('residence_location'),
            ExportColumn::make('salary'),
            ExportColumn::make('eligible_loan_amount'),
            ExportColumn::make('current_location'),
            ExportColumn::make('company_category'),
            ExportColumn::make('bank_eligible_for'),
            ExportColumn::make('other_bank_eligible_for'),
            ExportColumn::make('loan_applied'),
            ExportColumn::make('channel'),
            ExportColumn::make('sfl_remarks'),
            ExportColumn::make('underwriting_remarks'),
            ExportColumn::make('approved_remarks'),
            ExportColumn::make('sanctioned_remarks'),
            ExportColumn::make('not_approved_remarks'),
            ExportColumn::make('other_loan_applied'),
            ExportColumn::make('eligibility_status'),
            ExportColumn::make('eligibility_reason'),
            ExportColumn::make('journey_status'),
            ExportColumn::make('documentation_status'),
            ExportColumn::make('pending_document'),
            ExportColumn::make('journey_not_approved_reason'),
            ExportColumn::make('application_no'),
            ExportColumn::make('lan_no'),
            ExportColumn::make('sanctioned_bank'),
            ExportColumn::make('sanctioned_loan_amount'),
            ExportColumn::make('cashback'),
            ExportColumn::make('subvention'),
            ExportColumn::make('payout_rate'),
            ExportColumn::make('bank_condition'),
            ExportColumn::make('attachment_required'),
            ExportColumn::make('attachment_file'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
            ExportColumn::make('employee_id'),
            ExportColumn::make('assign_to'),

            ExportColumn::make('employee.emp_id')
                ->label('Caller EMP ID'),
            ExportColumn::make('employee.emp_name')
                ->label('Caller Name'),

            ExportColumn::make('employee.superviser.emp_id')
                ->label('Team Leader EMP ID'),

            ExportColumn::make('employee.superviser.emp_name')
                ->label('Team Leader Name'),

            ExportColumn::make('employee.manager.emp_id')
                ->label('Manager EMP ID'),

            ExportColumn::make('employee.manager.emp_name')
                ->label('Manager Name'),

            ExportColumn::make('employee.clusterManager.emp_id')
                ->label('Cluster Manager EMP ID'),

            ExportColumn::make('employee.clusterManager.emp_name')
                ->label('Cluster Manager Name'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your customer export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'employee.superviser',
            'employee.manager',
            'employee.clusterManager',
        ]);
    }
}
