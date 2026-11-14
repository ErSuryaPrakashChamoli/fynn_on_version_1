<?php

namespace App\Filament\Resources\AiCustomerRecords\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use Filament\Resources\Pages\ListRecords;

class ListAiCustomerRecords extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = AiCustomerRecordResource::class;
}
