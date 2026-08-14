<?php

namespace App\Filament\Resources\UserLoginSessions\Pages;

use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUserLoginSession extends CreateRecord
{
    protected static string $resource = UserLoginSessionResource::class;
}
