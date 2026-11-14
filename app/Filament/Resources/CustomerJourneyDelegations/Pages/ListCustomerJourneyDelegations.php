<?php

namespace App\Filament\Resources\CustomerJourneyDelegations\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerJourneyDelegations extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerJourneyDelegationResource::class;
}
