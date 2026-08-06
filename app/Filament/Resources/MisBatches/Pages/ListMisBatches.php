<?php

namespace App\Filament\Resources\MisBatches\Pages;

use App\Filament\Resources\MisBatches\MisBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMisBatches extends ListRecords
{
    protected static string $resource = MisBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
