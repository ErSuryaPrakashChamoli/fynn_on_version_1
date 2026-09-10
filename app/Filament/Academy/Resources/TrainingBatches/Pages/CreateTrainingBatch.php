<?php

namespace App\Filament\Academy\Resources\TrainingBatches\Pages;

use App\Filament\Academy\Resources\TrainingBatches\TrainingBatchResource;
use App\Support\Portal\PortalContext;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingBatch extends CreateRecord
{
    protected static string $resource = TrainingBatchResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(PortalContext::class)->tenantId();

        return $data;
    }
}
