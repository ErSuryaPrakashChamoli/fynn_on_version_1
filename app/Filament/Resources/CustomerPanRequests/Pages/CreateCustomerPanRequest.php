<?php

namespace App\Filament\Resources\CustomerPanRequests\Pages;

use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerPanRequest extends CreateRecord
{
    protected static string $resource = CustomerPanRequestResource::class;
}
