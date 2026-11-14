<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Positions\PositionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePositions extends ManageRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
