<?php

namespace App\Filament\Resources\MisBatches\Pages;

use App\Filament\Resources\MisBatches\MisBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMisBatch extends EditRecord
{
    protected static string $resource = MisBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
