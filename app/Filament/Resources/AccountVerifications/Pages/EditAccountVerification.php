<?php

namespace App\Filament\Resources\AccountVerifications\Pages;

use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountVerification extends EditRecord
{
    protected static string $resource = AccountVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['account_verified']) {

            $data['account_verified_by'] = auth()->id();

            $data['account_verified_at'] = now();

            $data['incentive_calculated'] = false;
        }

        return $data;
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole([
            'Admin',
            'Accounts',
        ]);
    }
}
