<?php

namespace App\Filament\Academy\Resources\TrainingCourses\Pages;

use App\Filament\Academy\Resources\TrainingCourses\TrainingCourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingCourses extends ListRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
