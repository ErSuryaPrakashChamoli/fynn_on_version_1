<?php

namespace App\Filament\Resources\AccountVerifications\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class AccountVerificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
                TextInput::make('sanctioned_loan_amount')
                    ->numeric()
                    ->required(),

                TextInput::make('cashback')
                    ->numeric(),

                TextInput::make('subvention')
                    ->numeric(),

                TextInput::make('docking')
                    ->numeric(),

                Textarea::make('account_remark'),

                Toggle::make('account_verified'),
            ]);
    }
}
