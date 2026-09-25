<?php

namespace App\Filament\Resources\Announcements\Schemas;

use App\Models\Announcement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->description('Saving a new announcement sends it to every active user\'s notification bell and floats it on their screen until they dismiss it.')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),

                        Textarea::make('message')
                            ->required()
                            ->rows(5)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        Select::make('level')
                            ->label('Type')
                            ->options(Announcement::LEVELS)
                            ->default('info')
                            ->required(),

                        DateTimePicker::make('expires_at')
                            ->label('Stop floating after')
                            ->helperText('Leave blank to keep it floating until each user dismisses it.')
                            ->seconds(false)
                            ->minDate(now()),

                        Toggle::make('is_active')
                            ->label('Floating on screen')
                            ->helperText('Switch off to stop showing it on screen. It stays in everyone\'s notification bell.')
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
