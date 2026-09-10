<?php

namespace App\Filament\Demo\Resources\DemoApplications\Pages;

use App\Filament\Demo\Resources\DemoApplications\DemoApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoApplications extends ListRecords
{
    protected static string $resource = DemoApplicationResource::class;
}
