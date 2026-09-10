<?php

namespace App\Filament\Demo\Resources\DemoBanks\Pages;

use App\Filament\Demo\Resources\DemoBanks\DemoBankResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoBanks extends ListRecords
{
    protected static string $resource = DemoBankResource::class;
}
