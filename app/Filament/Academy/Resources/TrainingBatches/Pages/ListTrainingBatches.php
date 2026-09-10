<?php

namespace App\Filament\Academy\Resources\TrainingBatches\Pages;

use App\Filament\Academy\Resources\TrainingBatches\TrainingBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingBatches extends ListRecords
{
    protected static string $resource = TrainingBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
