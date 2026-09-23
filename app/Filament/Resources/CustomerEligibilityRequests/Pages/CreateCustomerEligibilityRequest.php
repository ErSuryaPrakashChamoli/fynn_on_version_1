<?php

namespace App\Filament\Resources\CustomerEligibilityRequests\Pages;

use App\Filament\Resources\CustomerEligibilityRequests\CustomerEligibilityRequestResource;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomerEligibilityRequest extends CreateRecord
{
    protected static string $resource = CustomerEligibilityRequestResource::class;

    protected static ?string $title = 'New Eligibility Request';

    protected static bool $canCreateAnother = false;

    /**
     * The request goes through the service, which re-authorises the file —
     * a crafted post must not raise a request on somebody else's customer.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        $customer = Customer::find($data['customer_id'] ?? null);

        if (! $user instanceof User || ! $customer) {
            throw ValidationException::withMessages(['data.customer_id' => 'Choose a customer.']);
        }

        try {
            return app(CustomerEligibilityService::class)->raiseRequest($user, $customer, $data['reason']);
        } catch (AuthorizationException $exception) {
            throw ValidationException::withMessages(['data.customer_id' => $exception->getMessage()]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Request sent to the Admin';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
