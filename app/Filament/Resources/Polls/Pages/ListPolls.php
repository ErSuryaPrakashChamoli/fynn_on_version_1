<?php

namespace App\Filament\Resources\Polls\Pages;

use App\Filament\Resources\Polls\PollResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPolls extends ListRecords
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Raise a poll')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
