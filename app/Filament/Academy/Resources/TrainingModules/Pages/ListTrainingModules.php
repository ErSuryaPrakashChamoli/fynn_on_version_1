<?php

namespace App\Filament\Academy\Resources\TrainingModules\Pages;

use App\Filament\Academy\Resources\TrainingModules\TrainingModuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingModules extends ListRecords
{
    protected static string $resource = TrainingModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
