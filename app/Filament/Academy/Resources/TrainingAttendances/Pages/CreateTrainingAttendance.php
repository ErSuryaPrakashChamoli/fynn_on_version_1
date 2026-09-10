<?php

namespace App\Filament\Academy\Resources\TrainingAttendances\Pages;

use App\Filament\Academy\Resources\TrainingAttendances\TrainingAttendanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingAttendance extends CreateRecord
{
    protected static string $resource = TrainingAttendanceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['marked_by'] = auth()->id();

        return $data;
    }
}
