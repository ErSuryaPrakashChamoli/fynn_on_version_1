<?php

namespace App\Filament\Resources\AiDocumentSchemas\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\AiDocumentSchemas\AiDocumentSchemaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiDocumentSchemas extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = AiDocumentSchemaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Data Template'),
        ];
    }
}
