<?php

namespace App\Filament\Resources\CustomerEditRequests\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerEditRequests extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerEditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Request an edit')
                ->icon('heroicon-o-pencil-square'),
        ];
    }
}
