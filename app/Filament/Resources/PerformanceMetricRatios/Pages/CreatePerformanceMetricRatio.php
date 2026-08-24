<?php

namespace App\Filament\Resources\PerformanceMetricRatios\Pages;

use App\Filament\Resources\PerformanceMetricRatios\PerformanceMetricRatioResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePerformanceMetricRatio extends CreateRecord
{
    protected static string $resource = PerformanceMetricRatioResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()?->employee?->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
