<?php

namespace App\Filament\Imports;

use App\Models\Customer;
use App\Services\CustomerEligibilityService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class CustomerImporter extends Importer
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('customer_name')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('mobile_no')
                ->requiredMapping()
                ->rules(['required', 'max:20']),
            ImportColumn::make('email')
                ->rules(['email', 'max:255']),
            ImportColumn::make('pan_number')
                ->rules(['max:255']),
            ImportColumn::make('job_location')
                ->rules(['max:255']),
            ImportColumn::make('residence_location')
                ->rules(['max:255']),
            ImportColumn::make('salary')
                ->numeric()
                ->rules(['integer']),
            ImportColumn::make('current_location')
                ->rules(['max:255']),
            ImportColumn::make('company_category')
                ->rules(['max:255']),
            ImportColumn::make('bank_eligible_for')
                ->rules(['max:255']),
            ImportColumn::make('other_bank_eligible_for')
                ->rules(['max:255']),
            ImportColumn::make('loan_applied')
                ->rules(['max:255']),
            ImportColumn::make('channel')
                ->rules(['max:255']),
            ImportColumn::make('sfl_remarks'),
            ImportColumn::make('underwriting_remarks'),
            ImportColumn::make('approved_remarks'),
            ImportColumn::make('sanctioned_remarks'),
            ImportColumn::make('not_approved_remarks'),
            ImportColumn::make('other_loan_applied')
                ->rules(['max:255']),
            ImportColumn::make('eligibility_status')
                ->requiredMapping()
                ->rules(['required']),
            ImportColumn::make('eligibility_reason')
                ->rules(['max:255']),
            ImportColumn::make('journey_status')
                ->rules(['max:255']),
            ImportColumn::make('documentation_status')
                ->rules(['max:255']),
            ImportColumn::make('pending_document'),
            ImportColumn::make('journey_not_approved_reason')
                ->rules(['max:255']),
            ImportColumn::make('application_no')
                ->rules(['max:255']),
            ImportColumn::make('lan_no')
                ->rules(['max:255']),
            ImportColumn::make('sanctioned_bank')
                ->rules(['max:255']),
            ImportColumn::make('sanctioned_loan_amount')
                ->numeric()
                ->rules(['integer']),
            ImportColumn::make('cashback')
                ->numeric()
                ->rules(['integer']),
            ImportColumn::make('subvention')
                ->numeric()
                ->rules(['integer']),
            ImportColumn::make('payout_rate')
                ->numeric()
                ->rules(['integer']),
            ImportColumn::make('bank_condition'),
            ImportColumn::make('attachment_required'),
            ImportColumn::make('attachment_file')
                ->rules(['max:255']),
            ImportColumn::make('employee_id')
                ->numeric()
                ->rules(['integer']),
        ];
    }

    public function resolveRecord(): Customer
    {
        return new Customer;
    }

    /**
     * Imported files start their eligibility log the same way files created
     * in the UI do (CreateCustomer::afterCreate), so the log never begins
     * mid-story. The importer only ever creates, never updates, so it can
     * never move a file's eligibility behind the service's back.
     */
    protected function afterSave(): void
    {
        app(CustomerEligibilityService::class)->logCreation($this->record, $this->import->user);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your customer import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
