<?php

namespace App\Filament\Resources\ComplaintCategories\Pages;

use App\Filament\Resources\ComplaintCategories\ComplaintCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplaintCategories extends ListRecords
{
    protected static string $resource = ComplaintCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
