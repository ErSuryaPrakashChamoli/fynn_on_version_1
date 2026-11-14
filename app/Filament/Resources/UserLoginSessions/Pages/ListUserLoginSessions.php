<?php

namespace App\Filament\Resources\UserLoginSessions\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListUserLoginSessions extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = UserLoginSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
