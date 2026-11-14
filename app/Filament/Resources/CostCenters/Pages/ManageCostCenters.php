<?php

namespace App\Filament\Resources\CostCenters\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CostCenters\CostCenterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCenters extends ManageRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
