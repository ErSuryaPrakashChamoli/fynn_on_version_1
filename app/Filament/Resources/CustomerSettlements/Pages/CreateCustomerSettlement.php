<?php

namespace App\Filament\Resources\CustomerSettlements\Pages;

use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerSettlement extends CreateRecord
{
    protected static string $resource = CustomerSettlementResource::class;

    public static function canCreate(): bool
    {
        return false;
    }
}
