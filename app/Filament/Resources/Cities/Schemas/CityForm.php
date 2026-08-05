<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //

                TextInput::make('country')
                    ->required()
                    ->default('India')
                    ->maxLength(255),

                TextInput::make('state')
                    ->required()
                    ->maxLength(255),

                TextInput::make('city')
                    ->required()
                    ->maxLength(255),

                TextInput::make('state_code')
                    ->label('State Code')
                    ->maxLength(10),

                TextInput::make('city_code')
                    ->label('City Code')
                    ->maxLength(10),

                TextInput::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
