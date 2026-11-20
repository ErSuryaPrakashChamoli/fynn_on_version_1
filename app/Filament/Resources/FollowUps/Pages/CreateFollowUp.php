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

    /**
     * Back to "My Customer Follow-ups", where the new entry and its remarks
     * are listed — not the edit page, which made the follow-up look gone and
     * invited overwriting a log row instead of logging a new one.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
