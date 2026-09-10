<?php

namespace App\Filament\Demo\Resources\DemoEmployees\Pages;

use App\Filament\Demo\Resources\DemoEmployees\DemoEmployeeResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoEmployees extends ListRecords
{
    protected static string $resource = DemoEmployeeResource::class;
}
