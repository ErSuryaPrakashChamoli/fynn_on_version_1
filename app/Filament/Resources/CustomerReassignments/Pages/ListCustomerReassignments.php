<?php

namespace App\Filament\Resources\CustomerReassignments\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerReassignments extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerReassignmentResource::class;
}
