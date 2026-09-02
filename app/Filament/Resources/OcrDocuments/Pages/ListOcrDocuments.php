<?php

namespace App\Filament\Resources\OcrDocuments\Pages;

use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOcrDocuments extends ListRecords
{
    protected static string $resource = OcrDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Upload Document')];
    }
}
