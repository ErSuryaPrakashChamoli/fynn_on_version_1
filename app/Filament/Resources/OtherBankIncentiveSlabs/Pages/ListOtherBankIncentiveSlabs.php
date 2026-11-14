<?php

namespace App\Filament\Resources\OtherBankIncentiveSlabs\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\OtherBankIncentiveSlabs\OtherBankIncentiveSlabResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOtherBankIncentiveSlabs extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = OtherBankIncentiveSlabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
