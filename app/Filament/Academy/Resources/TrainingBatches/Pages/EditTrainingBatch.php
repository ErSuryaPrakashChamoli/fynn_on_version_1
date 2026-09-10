<?php

namespace App\Filament\Academy\Resources\TrainingBatches\Pages;

use App\Filament\Academy\Resources\TrainingBatches\TrainingBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingBatch extends EditRecord
{
    protected static string $resource = TrainingBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['tenant_id']);

        return $data;
    }
}
