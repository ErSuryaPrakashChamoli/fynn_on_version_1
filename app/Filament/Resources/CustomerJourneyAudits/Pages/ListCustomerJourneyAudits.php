<?php

namespace App\Filament\Resources\CustomerJourneyAudits\Pages;

use App\Filament\Resources\CustomerJourneyAudits\CustomerJourneyAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerJourneyAudits extends ListRecords
{
    protected static string $resource = CustomerJourneyAuditResource::class;
}
