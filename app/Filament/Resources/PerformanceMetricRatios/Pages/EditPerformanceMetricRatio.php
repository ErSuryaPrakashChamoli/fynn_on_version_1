<?php

namespace App\Filament\Resources\PerformanceMetricRatios\Pages;

use App\Filament\Resources\PerformanceMetricRatios\PerformanceMetricRatioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPerformanceMetricRatio extends EditRecord
{
    protected static string $resource = PerformanceMetricRatioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
