<?php

namespace App\Filament\Academy\Resources\TrainingLessons\Pages;

use App\Filament\Academy\Resources\TrainingLessons\TrainingLessonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingLessons extends ListRecords
{
    protected static string $resource = TrainingLessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
