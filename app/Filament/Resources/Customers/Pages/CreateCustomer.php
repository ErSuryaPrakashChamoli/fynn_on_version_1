<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\Customer;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use App\Models\CustomerPanRequest;
use Filament\Forms\Components\TextInput;

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


    public function mount(): void
    {
        parent::mount();

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

        $customer = $this->panRequest->customer;

        $this->existingCustomer = $customer;
        $this->isDuplicatePanFlow = true;
        $this->isApprovedPanRequest = true;

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





    // protected function getFormActions(): array
    // {
    //     return array_filter([
    //         filled($this->data['existing_customer_id'] ?? null)
    //             ? null
    //             : $this->getCreateFormAction(),

    //         $this->getCreateAnotherFormAction(),
    //         $this->getCancelFormAction(),
    //     ]);
    // }


    // protected function getCreateFormAction(): Action
    // {
    //     return parent::getCreateFormAction()
    //         ->hidden(fn() => filled($this->data['existing_customer_id'] ?? null));
    // }

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
        $data['employee_id'] = $user->employee_id;

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
                $data['journey_status'] = 'not_started';
                break;

            case 'not_eligible':
                $data['journey_status'] = 'not_started';
                break;
        }

        // $data['journey_status'] = 'sfl';


        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
