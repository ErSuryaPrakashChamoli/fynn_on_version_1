<?php

namespace App\Filament\Resources\AssignedLeads\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use Filament\Resources\Pages\ListRecords;

class ListAssignedLeads extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = AssignedLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
