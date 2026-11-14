<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
