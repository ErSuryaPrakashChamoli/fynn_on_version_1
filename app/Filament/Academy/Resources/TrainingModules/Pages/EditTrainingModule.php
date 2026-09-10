<?php

namespace App\Filament\Academy\Resources\TrainingModules\Pages;

use App\Filament\Academy\Resources\TrainingModules\TrainingModuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingModule extends EditRecord
{
    protected static string $resource = TrainingModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
