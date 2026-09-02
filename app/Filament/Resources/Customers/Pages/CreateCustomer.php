<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\AiCustomerRecord;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use App\Models\CustomerPanRequest;
use Filament\Forms\Components\TextInput;

use App\Models\Employee;



class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;


    public ?Customer $existingCustomer = null;
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

                $panNumber = filled($aiRecord->value('pan_number'))
                    ? strtoupper($aiRecord->value('pan_number'))
                    : null;

                $fill = [
                    'pan_number' => $panNumber,
                    'customer_name' => $aiRecord->value('customer_name'),
                    'mobile_no' => $aiRecord->value('mobile_number'),
                    'loan_applied' => $aiRecord->value('product_type'),
                    'email' => $aiRecord->value('email'),
                    'current_location' => $aiRecord->value('current_location'),
                    'job_location' => $aiRecord->value('job_location'),
                    'residence_location' => $aiRecord->value('residence_location'),
                    'salary' => $aiRecord->value('salary'),
                ];

                // Run the same duplicate-PAN lookup used by the live
                // pan_number field, since the field is pre-filled here
                // and its afterStateUpdated hook will not fire on blur.
                $existingCustomer = $panNumber
                    ? Customer::where('pan_number', $panNumber)->first()
                    : null;

                if ($existingCustomer) {
                    $fill['existing_customer_id'] = $existingCustomer->id;
                    $fill['pan_status'] = 'exists';
                    $fill['customer_name'] = $existingCustomer->customer_name;
                    $fill['mobile_no'] = $existingCustomer->mobile_no;
                    $fill['email'] = $existingCustomer->email;

                    $this->existingCustomer = $existingCustomer;
                    $this->panVerified = false;
                } else {
                    $fill['pan_status'] = $panNumber ? 'available' : '';
                    $this->panVerified = (bool) $panNumber;
                }

                $this->form->fill($fill);
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

                $panNumber = filled($lead->pan_number) ? strtoupper($lead->pan_number) : null;

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

                // Run the same duplicate-PAN lookup used by the live
                // pan_number field, since the field is pre-filled here
                // and its afterStateUpdated hook will not fire on blur.
                $existingCustomer = $panNumber
                    ? Customer::where('pan_number', $panNumber)->first()
                    : null;

                if ($existingCustomer) {
                    $fill['existing_customer_id'] = $existingCustomer->id;
                    $fill['pan_status'] = 'exists';
                    $fill['customer_name'] = $existingCustomer->customer_name;
                    $fill['mobile_no'] = $existingCustomer->mobile_no;
                    $fill['email'] = $existingCustomer->email;

                    $this->existingCustomer = $existingCustomer;
                    $this->panVerified = false;
                } else {
                    $fill['pan_status'] = $panNumber ? 'available' : '';
                    $this->panVerified = (bool) $panNumber;
                }

                $this->form->fill($fill);
            }
        }

        $panRequestId = request()->integer('pan_request');

        if (! $panRequestId) {
            return;
        }

        $this->panRequest = CustomerPanRequest::with('customer')
            ->findOrFail($panRequestId);

        abort_unless(
            $this->panRequest->status === CustomerPanRequest::STATUS_APPROVED,
            403
        );


        abort_unless(
            $this->panRequest->requested_by === auth()->user()->employee->id,
            403
        );



        if (filled($this->panRequest?->application_id)) {
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

        $this->form->fill([

            // Existing customer
            'existing_customer_id' => $customer->id,

            'pan_number' => $customer->pan_number,
            'customer_name' => $customer->customer_name,
            'mobile_no' => $customer->mobile_no,
            'email' => $customer->email,

            // Approved request data
            'requested_bank_id' => $this->panRequest->requested_bank_id,
            // 'loan_type' => $this->panRequest->requested_loan_type,
            'loan_applied' => $this->panRequest->requested_loan_type,
        ]);
    }


    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->hidden(function (): bool {
                return filled($this->data['existing_customer_id'] ?? null)
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
            ]);
        }

        if ($this->aiCustomerRecordId) {
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
            ]);
        }

        if ($this->leadId) {
            Lead::where('id', $this->leadId)->update([
                'is_converted' => true,
                'converted_customer_id' => $this->record->id,
            ]);
        }
    }



    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
