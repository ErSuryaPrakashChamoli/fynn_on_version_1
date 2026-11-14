<?php

namespace App\Filament\Academy\Resources\TrainingCourses\Pages;

use App\Filament\Academy\Resources\TrainingCourses\TrainingCourseResource;
use App\Filament\Concerns\HasColumnHeaderFilters;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingCourses extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TrainingCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
