<?php

namespace App\Filament\Resources\PerformanceMetricRatios\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\PerformanceMetricRatios\PerformanceMetricRatioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPerformanceMetricRatios extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = PerformanceMetricRatioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
