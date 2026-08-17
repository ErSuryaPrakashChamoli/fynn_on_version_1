<?php

namespace App\Filament\Resources\AccountVerifications\Pages;

use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountVerification extends CreateRecord
{
    protected static string $resource = AccountVerificationResource::class;
}
