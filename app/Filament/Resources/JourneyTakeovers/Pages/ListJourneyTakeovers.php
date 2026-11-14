<?php

namespace App\Filament\Resources\JourneyTakeovers\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use Filament\Resources\Pages\ListRecords;

class ListJourneyTakeovers extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = JourneyTakeoverResource::class;
}
