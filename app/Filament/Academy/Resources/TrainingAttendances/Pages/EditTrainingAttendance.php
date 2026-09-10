<?php

namespace App\Filament\Academy\Resources\TrainingAttendances\Pages;

use App\Filament\Academy\Resources\TrainingAttendances\TrainingAttendanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingAttendance extends EditRecord
{
    protected static string $resource = TrainingAttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
