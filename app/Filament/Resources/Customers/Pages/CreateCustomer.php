<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\AiCustomerRecord;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\Cibil\CibilReportService;
use App\Services\CustomerEligibilityService;
use App\Services\PanDuplicateService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    public ?Customer $existingCustomer = null;

    /**
     * An open lead that already carries the typed PAN.
     */
    public ?Lead $existingLead = null;

    public bool $panExists = false;

    public bool $panVerified = false;

    public bool $approvalRequested = false;

    public bool $isDuplicatePanFlow = false;

    public bool $isApprovedPanRequest = false;

    public bool $showPanRequests = false;

    public bool $isDirectCustomer = false;

    public ?CustomerPanRequest $panRequest = null;

    public ?int $aiCustomerRecordId = null;

    public ?int $leadId = null;

    public function mount(): void
    {
        parent::mount();

        /*
    |--------------------------------------------------------------------------
    | Direct Customer Mode
    |--------------------------------------------------------------------------
    */

        $this->isDirectCustomer = request()->boolean('direct');

        if ($this->isDirectCustomer) {

            $employee = auth()->user()->employee;

            abort_unless(
                $employee &&
                    in_array(
                        $employee->designation,
                        [
                            Employee::DESIGNATION_TEAM_LEADER,
                            Employee::DESIGNATION_MANAGER,
                        ],
                        true
                    ),
                403
            );

            // Direct customer always belongs to creator.
            $this->form->fill([
                'assign_to' => $employee->id,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Converting an assigned AI-extracted lead
        |--------------------------------------------------------------------------
        */

        $aiCustomerRecordId = request()->integer('ai_customer_record') ?: null;

        if ($aiCustomerRecordId) {
            $aiRecord = AiCustomerRecord::find($aiCustomerRecordId);

            if ($aiRecord) {

                if (filled($aiRecord->customer_id)) {
                    Notification::make()
                        ->title('Already Converted')
                        ->body('This record has already been converted to a customer.')
                        ->warning()
                        ->send();

                    $this->redirect(CustomerResource::getUrl('index'));

                    return;
                }

                $this->aiCustomerRecordId = $aiRecord->id;

                // The upload template may store these under its own keys
                // ("mobile", "pan", "city"...) — prospectValue() finds them.
                $panNumber = PanDuplicateService::normalize($aiRecord->prospectValue('pan_number'));

                $fill = [
                    'pan_number' => $panNumber,
                    'customer_name' => $aiRecord->prospectValue('customer_name'),
                    'mobile_no' => $aiRecord->prospectValue('mobile_number'),
                    'loan_applied' => $aiRecord->value('product_type'),
                    'email' => $aiRecord->prospectValue('email'),
                    'current_location' => $aiRecord->prospectValue('current_location'),
                    'job_location' => $aiRecord->prospectValue('job_location'),
                    'residence_location' => $aiRecord->prospectValue('residence_location'),
                    'salary' => $aiRecord->prospectValue('salary'),
                ];

                $this->form->fill($this->withPanCheck($fill));
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Converting a lead
        |--------------------------------------------------------------------------
        */

        $leadId = request()->integer('lead') ?: null;

        if ($leadId) {
            $lead = Lead::find($leadId);

            if ($lead) {

                if ($lead->is_converted) {
                    Notification::make()
                        ->title('Lead Already Converted')
                        ->body('This lead has already been converted to a customer.')
                        ->warning()
                        ->send();

                    $this->redirect(LeadResource::getUrl('index'));

                    return;
                }

                $this->leadId = $lead->id;

                $panNumber = PanDuplicateService::normalize($lead->pan_number);

                $fill = [
                    'pan_number' => $panNumber,
                    'customer_name' => $lead->customer_name,
                    'mobile_no' => $lead->mobile_no,
                    'email' => $lead->email,
                    'current_location' => $lead->current_location,
                    'job_location' => $lead->job_location,
                    'residence_location' => $lead->residence_location,
                    'salary' => $lead->salary,
                ];

                $this->form->fill($this->withPanCheck($fill));
            }
        }

        $panRequestId = request()->integer('pan_request');

        if (! $panRequestId) {
            return;
        }

        $this->panRequest = CustomerPanRequest::with(['customer', 'matchedLead', 'lead'])
            ->findOrFail($panRequestId);

        abort_unless(
            $this->panRequest->status === CustomerPanRequest::STATUS_APPROVED
                && ! $this->panRequest->isForLead(),
            403
        );

        abort_unless(
            $this->panRequest->requested_by === auth()->user()->employee->id,
            403
        );

        if ($this->panRequest->isUsed()) {
            Notification::make()
                ->title('PAN Request Already Used')
                ->body('This PAN approval request has already been used to create a customer.')
                ->warning()
                ->send();

            $this->redirect(
                CustomerResource::getUrl('index')
            );

            return;
        }

        $customer = $this->panRequest->customer;

        $this->existingCustomer = $customer;
        $this->isDuplicatePanFlow = true;
        $this->isApprovedPanRequest = true;

        if ($this->panRequest->ai_customer_record_id) {
            $this->aiCustomerRecordId = $this->panRequest->ai_customer_record_id;
        }

        if ($this->panRequest->lead_id) {
            $this->leadId = $this->panRequest->lead_id;
        }

        // Approved data shared by both kinds of clash
        $approved = [
            'pan_number' => $this->panRequest->pan_number,
            'requested_bank_id' => $this->panRequest->requested_bank_id,
            // 'loan_type' => $this->panRequest->requested_loan_type,
            'loan_applied' => $this->panRequest->requested_loan_type,
        ];

        if ($customer) {
            $this->form->fill([
                ...$approved,

                // Existing customer
                'existing_customer_id' => $customer->id,

                'pan_number' => $customer->pan_number,
                'customer_name' => $customer->customer_name,
                'mobile_no' => $customer->mobile_no,
                'email' => $customer->email,
            ]);

            return;
        }

        // The PAN clashed with an open lead: the person's details come from
        // the lead being converted, else from the clashing lead.
        $this->existingLead = $this->panRequest->matchedLead;
        $source = $this->panRequest->lead ?? $this->existingLead;

        $this->form->fill([
            ...$approved,
            'existing_lead_id' => $this->existingLead?->id,
            'customer_name' => $source?->customer_name,
            'mobile_no' => $source?->mobile_no,
            'email' => $source?->email,
            'current_location' => $source?->current_location,
            'job_location' => $source?->job_location,
            'residence_location' => $source?->residence_location,
            'salary' => $source?->salary,
        ]);
    }

    /**
     * Runs the duplicate-PAN lookup the live pan_number field would run, for
     * a form pre-filled on mount (its afterStateUpdated hook never fires
     * then). Searches customers and open leads; the lead being converted is
     * not a clash with itself.
     *
     * @param  array<string, mixed>  $fill
     * @return array<string, mixed>
     */
    private function withPanCheck(array $fill): array
    {
        $panNumber = $fill['pan_number'] ?? null;

        $match = app(PanDuplicateService::class)->firstMatch($panNumber, ignoreLeadId: $this->leadId);

        if ($match?->isCustomer()) {
            $existingCustomer = Customer::find($match->id);

            $fill['existing_customer_id'] = $existingCustomer->id;
            $fill['pan_status'] = 'exists';
            $fill['customer_name'] = $existingCustomer->customer_name;
            $fill['mobile_no'] = $existingCustomer->mobile_no;
            $fill['email'] = $existingCustomer->email;

            $this->existingCustomer = $existingCustomer;
            $this->panVerified = false;

            return $fill;
        }

        if ($match?->isLead()) {
            $fill['existing_lead_id'] = $match->id;
            $fill['pan_status'] = 'exists_lead';

            $this->existingLead = Lead::find($match->id);
            $this->panVerified = false;

            return $fill;
        }

        $fill['pan_status'] = $panNumber ? 'available' : '';
        $this->panVerified = (bool) $panNumber;

        return $fill;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->hidden(function (): bool {
                return (filled($this->data['existing_customer_id'] ?? null) || filled($this->data['existing_lead_id'] ?? null))
                    && ! $this->isApprovedPanRequest;
            });
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $employee = $user->employee;

        abort_unless($employee, 403, 'Employee profile not found.');

        // Customer always belongs to the employee
        // who is creating it.
        $data['employee_id'] = $employee?->id;
        $data['assign_to'] = $employee?->id;

        $data['direct'] = $this->isDirectCustomer;

        if (! $employee) {
            abort(403, 'Employee profile not found.');
        }

        // if ($this->panExists) {
        //     Notification::make()
        //         ->title('Customer already exists.')
        //         ->danger()
        //         ->send();

        //     $this->halt();
        // }

        /*
        |--------------------------------------------------------------------------
        | Direct Customer
        |--------------------------------------------------------------------------
        */

        if ($this->isDirectCustomer) {

            abort_unless(
                in_array(
                    $employee->designation,
                    [
                        Employee::DESIGNATION_TEAM_LEADER,
                        Employee::DESIGNATION_MANAGER,
                    ],
                    true
                ),
                403
            );

            // Direct customer belongs to the person creating it.
            $data['employee_id'] = $employee->id;
            $data['assign_to'] = $employee->id;
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Customer
        |--------------------------------------------------------------------------
        */ else {

            // Existing behaviour.
            $data['employee_id'] = $employee->id;
        }

        if ($this->panExists) {
            Notification::make()
                ->title('Customer already exists.')
                ->danger()
                ->send();

            $this->halt();
        }

        // The approved flow may only create the file with the PAN the Admin
        // approved (the field is locked, but never trust the browser).
        if ($this->isApprovedPanRequest && $this->panRequest) {
            $data['pan_number'] = $this->panRequest->pan_number;
        }

        switch ($data['eligibility_status']) {

            case 'eligible':
                $data['journey_status'] = 'sfl';
                break;

            case 'consent_pending':
            case 'not_eligible':
                $data['journey_status'] = 'not_started';
                break;
        }

        return $data;
    }
    // protected function mutateFormDataBeforeCreate(array $data): array
    // {

    //     $user = auth()->user();
    //     $data['employee_id'] = $user->employee_id;

    //     if ($this->panExists) {
    //         Notification::make()
    //             ->title('Customer already exists.')
    //             ->danger()
    //             ->send();

    //         $this->halt();
    //     }

    //     switch ($data['eligibility_status']) {

    //         case 'eligible':
    //             $data['journey_status'] = 'sfl';
    //             break;

    //         case 'consent_pending':
    //             $data['journey_status'] = 'not_started';
    //             break;

    //         case 'not_eligible':
    //             $data['journey_status'] = 'not_started';
    //             break;
    //     }

    //     // $data['journey_status'] = 'sfl';

    //     return $data;
    // }

    protected function afterCreate(): void
    {
        if ($this->isApprovedPanRequest && $this->panRequest) {
            $this->panRequest->updateOrFail([
                'application_id' => $this->record->id,
                'used_at' => now(),
            ]);
        }

        $convertedAssignmentIds = [];

        if ($this->aiCustomerRecordId) {
            $convertedAssignmentIds = CustomerAssignment::where('ai_customer_record_id', $this->aiCustomerRecordId)->pluck('id')->all();

            AiCustomerRecord::where('id', $this->aiCustomerRecordId)->update([
                'customer_id' => $this->record->id,
            ]);

            FollowUp::where('ai_customer_record_id', $this->aiCustomerRecordId)->update([
                'customer_id' => $this->record->id,
                'ai_customer_record_id' => null,
            ]);

            CustomerAssignment::where('ai_customer_record_id', $this->aiCustomerRecordId)->update([
                'customer_id' => $this->record->id,
                'ai_customer_record_id' => null,
                'converted_at' => now(),
            ]);
        }

        if ($this->leadId) {
            Lead::where('id', $this->leadId)->update([
                'is_converted' => true,
                'converted_customer_id' => $this->record->id,
            ]);
        }

        // The lead's CIBIL reports stay its history and now also show on the new file.
        app(CibilReportService::class)->linkConvertedLead($this->record, $this->leadId, $convertedAssignmentIds, auth()->user());

        app(CustomerEligibilityService::class)->logCreation($this->record, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
