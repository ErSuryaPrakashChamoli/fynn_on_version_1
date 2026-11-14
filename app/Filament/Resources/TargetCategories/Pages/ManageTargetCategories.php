<?php

namespace App\Filament\Resources\TargetCategories\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\TargetCategories\TargetCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTargetCategories extends ManageRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TargetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
