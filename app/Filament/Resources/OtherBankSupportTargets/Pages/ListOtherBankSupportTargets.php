<?php

namespace App\Filament\Resources\OtherBankSupportTargets\Pages;

use App\Filament\Resources\OtherBankSupportTargets\OtherBankSupportTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOtherBankSupportTargets extends ListRecords
{
    protected static string $resource = OtherBankSupportTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
