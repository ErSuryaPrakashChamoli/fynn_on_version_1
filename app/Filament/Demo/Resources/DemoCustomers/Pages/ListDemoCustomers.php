<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Pages;

use App\Filament\Demo\Resources\DemoCustomers\DemoCustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoCustomers extends ListRecords
{
    protected static string $resource = DemoCustomerResource::class;
}
