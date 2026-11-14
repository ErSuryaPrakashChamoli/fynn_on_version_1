<?php

namespace App\Filament\Academy\Resources\TrainingAttendances\Pages;

use App\Filament\Academy\Resources\TrainingAttendances\TrainingAttendanceResource;
use App\Filament\Concerns\HasColumnHeaderFilters;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingAttendances extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TrainingAttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Mark attendance'),
        ];
    }
}
