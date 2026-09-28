<?php

namespace App\Filament\Resources\PollTypes\Pages;

use App\Filament\Resources\PollTypes\PollTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPollType extends EditRecord
{
    protected static string $resource = PollTypeResource::class;

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
