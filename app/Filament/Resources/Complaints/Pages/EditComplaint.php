<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

/**
 * Only the wording changes here (description, attachments);
 * routing, priority and status change through the ticket page's actions
 * so every change is logged on the thread.
 */
class EditComplaint extends EditRecord
{
    protected static string $resource = ComplaintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'attachments' => array_values(array_filter((array) ($data['attachments'] ?? []))),
        ];
    }

    protected function afterSave(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User) {
            app(ComplaintService::class)->logEvent($this->record, $user, $user->name.' edited the ticket details.');
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
