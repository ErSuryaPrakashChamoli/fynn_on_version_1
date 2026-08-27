<?php

namespace App\Filament\Resources\CustomerReassignments\Pages;

use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerReassignments extends ListRecords
{
    protected static string $resource = CustomerReassignmentResource::class;
}
