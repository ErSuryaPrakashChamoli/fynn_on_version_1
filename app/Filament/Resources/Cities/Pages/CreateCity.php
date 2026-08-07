<?php

namespace App\Filament\Resources\Cities\Pages;

use App\Filament\Resources\Cities\CityResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\City;
use Filament\Notifications\Notification;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $exists = City::where('state', $data['state'])
            ->where('city', $data['city'])
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('City already exists')
                ->warning()
                ->send();

            $this->halt(); // Stop record creation
        }

        return $data;
    }
}
