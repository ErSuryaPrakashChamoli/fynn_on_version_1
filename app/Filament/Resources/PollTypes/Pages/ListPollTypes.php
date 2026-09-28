<?php

namespace App\Filament\Resources\PollTypes\Pages;

use App\Filament\Resources\PollTypes\PollTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPollTypes extends ListRecords
{
    protected static string $resource = PollTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
