<?php

namespace App\Filament\Resources\OtherBankSupportTargets\Pages;

use App\Filament\Resources\OtherBankSupportTargets\OtherBankSupportTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOtherBankSupportTarget extends EditRecord
{
    protected static string $resource = OtherBankSupportTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
