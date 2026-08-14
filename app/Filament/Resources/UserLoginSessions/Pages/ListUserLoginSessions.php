<?php

namespace App\Filament\Resources\UserLoginSessions\Pages;

use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserLoginSessions extends ListRecords
{
    protected static string $resource = UserLoginSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
