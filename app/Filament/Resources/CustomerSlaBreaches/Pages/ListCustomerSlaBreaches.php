<?php

namespace App\Filament\Resources\CustomerSlaBreaches\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerSlaBreaches\CustomerSlaBreachResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerSlaBreaches extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerSlaBreachResource::class;
}
