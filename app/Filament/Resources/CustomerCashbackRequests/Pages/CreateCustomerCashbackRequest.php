<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Pages;

use App\Enums\CashbackRequestStatus;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerCashbackRequest;
use App\Models\User;
use App\Services\CustomerCashbackService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomerCashbackRequest extends CreateRecord
{
    protected static string $resource = CustomerCashbackRequestResource::class;

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
     * The service checks the user against the file, the file's stage, and
     * that no other form is live on it.
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

        try {
            return app(CustomerCashbackService::class)->raise($user, $customer, $data);
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
        /** @var CustomerCashbackRequest $record */
        $record = $this->record;

        return Notification::make()
            ->success()
            ->title('Cashback form '.$record->voucher_no.' submitted')
            ->body(match ($record->status) {
                CashbackRequestStatus::PendingManager => 'It now goes to the Manager, then the Admin, for approval.',
                CashbackRequestStatus::PendingAdmin => 'It now goes to the Admin for approval.',
                default => 'Approved — Accounts can now pay it.',
            });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
