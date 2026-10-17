<?php

namespace App\Filament\Resources\CibilReports\Pages;

use App\Filament\Pages\CibilAccessSettings;
use App\Filament\Resources\CibilReports\CibilReportResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCibilReports extends ListRecords
{
    protected static string $resource = CibilReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageAccess')
                ->label('Manage access')
                ->icon('heroicon-o-lock-open')
                ->color('gray')
                ->url(CibilAccessSettings::getUrl())
                ->visible(fn (): bool => CibilAccessSettings::canAccess()),

            CreateAction::make()
                ->label('Fetch bureau report')
                ->icon('heroicon-o-magnifying-glass'),
        ];
    }
}
