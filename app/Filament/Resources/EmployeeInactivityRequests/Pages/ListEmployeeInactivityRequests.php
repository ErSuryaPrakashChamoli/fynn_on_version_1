<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Pages;

use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeInactivityRequests extends ListRecords
{
    protected static string $resource = EmployeeInactivityRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
