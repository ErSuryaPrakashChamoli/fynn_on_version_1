<?php

namespace App\Filament\Resources\ComplaintPriorities\Pages;

use App\Filament\Resources\ComplaintPriorities\ComplaintPriorityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplaintPriorities extends ListRecords
{
    protected static string $resource = ComplaintPriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
