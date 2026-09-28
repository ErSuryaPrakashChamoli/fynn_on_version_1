<?php

namespace App\Filament\Resources\Designations\Pages;

use App\Filament\Resources\Designations\DesignationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDesignations extends ManageRecords
{
    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
