<?php

namespace App\Filament\Academy\Resources\TrainingLessons\Pages;

use App\Filament\Academy\Resources\TrainingLessons\TrainingLessonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingLesson extends CreateRecord
{
    protected static string $resource = TrainingLessonResource::class;
}
