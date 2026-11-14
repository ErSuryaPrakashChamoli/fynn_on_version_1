<?php

namespace App\Filament\Academy\Resources\TrainingEnrollments\Pages;

use App\Filament\Academy\Resources\TrainingEnrollments\TrainingEnrollmentResource;
use App\Filament\Concerns\HasColumnHeaderFilters;
use Filament\Resources\Pages\ListRecords;

class ListTrainingEnrollments extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TrainingEnrollmentResource::class;
}
