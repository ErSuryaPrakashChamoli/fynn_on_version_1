<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes\Pages;

use App\Filament\Academy\Resources\TrainingQuizzes\TrainingQuizResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingQuizzes extends ListRecords
{
    protected static string $resource = TrainingQuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
