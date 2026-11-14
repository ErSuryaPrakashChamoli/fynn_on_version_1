<?php

namespace App\Filament\Resources\PendingManagerCases\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\PendingManagerCases\PendingManagerCaseResource;
use Filament\Resources\Pages\ListRecords;

class ListPendingManagerCases extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = PendingManagerCaseResource::class;
}
