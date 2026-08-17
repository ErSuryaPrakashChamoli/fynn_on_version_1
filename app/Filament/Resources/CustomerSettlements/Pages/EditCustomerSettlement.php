<?php

namespace App\Filament\Resources\CustomerSettlements\Pages;

use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerSettlement extends EditRecord
{
    protected static string $resource = CustomerSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
