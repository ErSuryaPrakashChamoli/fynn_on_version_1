<?php

namespace App\Filament\Resources\CustomerSettlements\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerSettlements extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
