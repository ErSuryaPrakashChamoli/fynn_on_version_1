<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes\Pages;

use App\Filament\Academy\Resources\TrainingQuizzes\TrainingQuizResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingQuiz extends EditRecord
{
    protected static string $resource = TrainingQuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
