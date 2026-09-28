<?php

namespace App\Filament\Resources\ComplaintCategories\Pages;

use App\Filament\Resources\ComplaintCategories\ComplaintCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComplaintCategory extends CreateRecord
{
    protected static string $resource = ComplaintCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
