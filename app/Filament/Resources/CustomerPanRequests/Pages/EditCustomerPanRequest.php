<?php

namespace App\Filament\Resources\CustomerPanRequests\Pages;

use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerPanRequest extends EditRecord
{
    protected static string $resource = CustomerPanRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
