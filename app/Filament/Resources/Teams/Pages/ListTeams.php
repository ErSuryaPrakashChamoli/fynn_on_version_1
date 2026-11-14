<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeams extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }
}
