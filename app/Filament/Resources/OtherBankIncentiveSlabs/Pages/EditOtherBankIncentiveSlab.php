<?php

namespace App\Filament\Resources\OtherBankIncentiveSlabs\Pages;

use App\Filament\Resources\OtherBankIncentiveSlabs\OtherBankIncentiveSlabResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOtherBankIncentiveSlab extends EditRecord
{
    protected static string $resource = OtherBankIncentiveSlabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
