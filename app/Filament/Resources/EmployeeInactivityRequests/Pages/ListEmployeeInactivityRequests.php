<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeInactivityRequests extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = EmployeeInactivityRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
