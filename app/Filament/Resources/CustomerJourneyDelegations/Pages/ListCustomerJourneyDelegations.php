<?php

namespace App\Filament\Resources\CustomerJourneyDelegations\Pages;

use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerJourneyDelegations extends ListRecords
{
    protected static string $resource = CustomerJourneyDelegationResource::class;
}
