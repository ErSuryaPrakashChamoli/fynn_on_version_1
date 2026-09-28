<?php

namespace App\Filament\Resources\CustomerEditRequests\Pages;

use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use App\Filament\Resources\CustomerEditRequests\Schemas\CustomerEditRequestForm;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\User;
use App\Services\CustomerEditRequestService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomerEditRequest extends CreateRecord
{
    protected static string $resource = CustomerEditRequestResource::class;

    protected static bool $canCreateAnother = false;

    /** Pre-fills the file when opened from a customer page (?customer=ID). */
    protected function afterFill(): void
    {
        $customerId = request()->integer('customer');

        if ($customerId && CustomerResource::getEloquentQuery()->whereKey($customerId)->exists()) {
            $this->form->fill(['customer_id' => $customerId]);
        }
    }

    /**
     * The service validates each field against the section, the value
     * against the field, and the user against the file, and never lets a
     * crafted post name a field outside the registry.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        $customer = CustomerResource::getEloquentQuery()->find((int) ($data['customer_id'] ?? 0));

        if (! $user instanceof User || ! $customer) {
            throw ValidationException::withMessages(['data.customer_id' => 'Pick a customer file you can see.']);
        }

        $section = (string) ($data['section'] ?? '');
        $changes = collect($data['changes'] ?? [])
            ->values()
            ->map(fn (array $row): array => [
                'field' => (string) ($row['field'] ?? ''),
                'value' => CustomerEditRequestForm::requestedValue($section, $row),
            ])
            ->all();

        try {
            return app(CustomerEditRequestService::class)->raise($user, $customer, $section, (string) ($data['reason'] ?? ''), $changes);
        } catch (AuthorizationException $exception) {
            throw ValidationException::withMessages(['data.customer_id' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])
                ->all());
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Edit request sent to the Admin')
            ->body('The file changes as soon as it is approved.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
