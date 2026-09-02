<?php

namespace App\Filament\Resources\FollowUps\Pages;

use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFollowUp extends CreateRecord
{
    protected static string $resource = FollowUpResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Accounts without a linked employee profile (e.g. the Admin
        // login) can still log a follow-up; it's just not attributed
        // to a specific employee.
        $data['employee_id'] = auth()->user()?->employee?->id;

        return $data;
    }
}
