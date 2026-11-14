<?php

namespace App\Filament\Resources\Cities\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Cities\CityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCities extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
