<?php

namespace App\Filament\Resources\CustomerJourneyAudits\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerJourneyAudits\CustomerJourneyAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerJourneyAudits extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerJourneyAuditResource::class;
}
