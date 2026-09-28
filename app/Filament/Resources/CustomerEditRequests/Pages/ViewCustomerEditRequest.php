<?php

namespace App\Filament\Resources\CustomerEditRequests\Pages;

use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestActions;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerEditRequest extends ViewRecord
{
    protected static string $resource = CustomerEditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CustomerEditRequestActions::approve(),
            CustomerEditRequestActions::reject(),
        ];
    }
}
