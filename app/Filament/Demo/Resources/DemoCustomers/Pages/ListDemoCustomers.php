<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Pages;

use App\Filament\Demo\Resources\DemoCustomers\DemoCustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDemoCustomers extends ListRecords
{
    protected static string $resource = DemoCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
