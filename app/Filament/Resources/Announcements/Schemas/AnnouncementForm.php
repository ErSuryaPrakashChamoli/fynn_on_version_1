<?php

namespace App\Filament\Resources\Announcements\Schemas;

use App\Models\Announcement;
use App\Models\Employee;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->description('Every recipient must read and acknowledge it before they can carry on using the LMS. It also stays in their notification bell to read again.')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),

                        Textarea::make('message')
                            ->required()
                            ->rows(6)
                            ->maxLength(5000)
                            ->columnSpanFull(),

                        Select::make('level')
                            ->label('Type')
                            ->options(Announcement::LEVELS)
                            ->default('info')
                            ->required(),

                        Flatpickr::make('expires_at')
                            ->label('Stop asking after')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            ->rule('after:now')
                            ->validationMessages(['after' => 'Pick a time in the future.'])
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar')
                            ->helperText('Leave blank to keep asking until everyone has acknowledged it.'),

                        Toggle::make('is_active')
                            ->label('Require acknowledgement')
                            ->helperText('Switch off to stop the pop-up for anyone who has not acknowledged it yet. It stays in everyone\'s notification bell.')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Send to')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'Pick who receives it. Only people whose login is active get it.'
                        : 'The audience is fixed once an announcement has been sent.')
                    ->schema([
                        ToggleButtons::make('audience')
                            ->label('Audience')
                            ->options(Announcement::AUDIENCES)
                            ->icons([
                                Announcement::AUDIENCE_COMPANY => 'heroicon-o-building-office-2',
                                Announcement::AUDIENCE_ROLES => 'heroicon-o-key',
                                Announcement::AUDIENCE_DESIGNATIONS => 'heroicon-o-identification',
                            ])
                            ->inline()
                            ->default(Announcement::AUDIENCE_COMPANY)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->columnSpanFull(),

                        Select::make('audience_roles')
                            ->label('Roles')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->required(fn (Get $get): bool => $get('audience') === Announcement::AUDIENCE_ROLES)
                            ->visible(fn (Get $get): bool => $get('audience') === Announcement::AUDIENCE_ROLES)
                            ->disabledOn('edit')
                            ->columnSpanFull(),

                        Select::make('audience_designations')
                            ->label('Designations')
                            ->multiple()
                            ->preload()
                            ->options(Employee::designationOptions())
                            ->required(fn (Get $get): bool => $get('audience') === Announcement::AUDIENCE_DESIGNATIONS)
                            ->visible(fn (Get $get): bool => $get('audience') === Announcement::AUDIENCE_DESIGNATIONS)
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
