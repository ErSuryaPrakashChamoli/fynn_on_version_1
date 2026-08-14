<?php

namespace App\Filament\Resources\UserLoginSessions\Pages;

use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUserLoginSession extends EditRecord
{
    protected static string $resource = UserLoginSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
