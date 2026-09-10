<?php

namespace App\Filament\Demo\Resources\DemoLoanProducts\Pages;

use App\Filament\Demo\Resources\DemoLoanProducts\DemoLoanProductResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoLoanProducts extends ListRecords
{
    protected static string $resource = DemoLoanProductResource::class;
}
