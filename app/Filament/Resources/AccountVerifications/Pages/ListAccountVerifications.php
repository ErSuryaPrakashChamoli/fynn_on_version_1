<?php

namespace App\Filament\Resources\AccountVerifications\Pages;

use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountVerifications extends ListRecords
{
    protected static string $resource = AccountVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
