<?php

namespace App\Filament\Academy\Resources\TrainingLessons\Pages;

use App\Filament\Academy\Resources\TrainingLessons\TrainingLessonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingLesson extends EditRecord
{
    protected static string $resource = TrainingLessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
