<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
