<?php

namespace App\Filament\Resources\CustomerPanRequests\Pages;

use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerPanRequests extends ListRecords
{
    protected static string $resource = CustomerPanRequestResource::class;

    protected static ?string $title = 'Duplicate PAN Request';

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }



    // public function getBreadcrumb(): string
    // {
    //     return 'Duplicate PAN Request';
    // }
}
