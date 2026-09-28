<?php

namespace App\Filament\Resources\ComplaintPriorities\Pages;

use App\Filament\Resources\ComplaintPriorities\ComplaintPriorityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComplaintPriority extends CreateRecord
{
    protected static string $resource = ComplaintPriorityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
