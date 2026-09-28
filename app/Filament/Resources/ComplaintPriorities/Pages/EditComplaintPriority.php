<?php

namespace App\Filament\Resources\ComplaintPriorities\Pages;

use App\Filament\Resources\ComplaintPriorities\ComplaintPriorityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditComplaintPriority extends EditRecord
{
    protected static string $resource = ComplaintPriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
