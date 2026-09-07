<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Pages;

use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use App\Filament\Resources\EmployeeInactivityRequests\Schemas\EmployeeInactivityRequestForm;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateEmployeeInactivityRequest extends CreateRecord
{
    protected static string $resource = EmployeeInactivityRequestResource::class;

    /**
     * Status and author are never taken from the request, and the target
     * is re-authorised here as well as on the form's option list — a
     * crafted post must not ticket somebody else's team member.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User
            || ! app(MonthlyTargetGate::class)->canSetTargetFor($user, (int) $data['employee_id'])) {
            throw ValidationException::withMessages([
                'data.employee_id' => 'You cannot raise a ticket for that employee.',
            ]);
        }

        return [...$data, ...EmployeeInactivityRequestForm::defaults()];
    }

    protected function afterCreate(): void
    {
        // The month may now be unblocked for this user's whole team.
        app(MonthlyTargetGate::class)->forget();
    }
}
