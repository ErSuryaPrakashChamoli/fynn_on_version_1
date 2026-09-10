<?php

namespace App\Filament\Demo\Resources\DemoLeads\Pages;

use App\Filament\Demo\Resources\DemoLeads\DemoLeadResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoLeads extends ListRecords
{
    protected static string $resource = DemoLeadResource::class;
}
