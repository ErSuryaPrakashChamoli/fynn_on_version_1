<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Pages;

use App\Filament\Demo\Resources\DemoCustomers\DemoCustomerResource;
use Filament\Resources\Pages\EditRecord;

class EditDemoCustomer extends EditRecord
{
    protected static string $resource = DemoCustomerResource::class;
}
