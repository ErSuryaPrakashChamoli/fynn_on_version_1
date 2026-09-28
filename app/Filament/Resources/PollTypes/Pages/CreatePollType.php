<?php

namespace App\Filament\Resources\PollTypes\Pages;

use App\Filament\Resources\PollTypes\PollTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePollType extends CreateRecord
{
    protected static string $resource = PollTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
