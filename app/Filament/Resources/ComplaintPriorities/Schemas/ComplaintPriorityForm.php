<?php

namespace App\Filament\Resources\ComplaintPriorities\Schemas;

use App\Models\ComplaintPriority;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ComplaintPriorityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Priority')
                    ->description('The hours set here become the deadline on every ticket raised with this priority. Past the deadline the ticket escalates to the handler\'s supervisor; after a second window of the same length, to the boss above.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(40)
                            ->unique(ignoreRecord: true),

                        TextInput::make('resolve_within_minutes')
                            ->label('Resolve within (minutes)')
                            ->helperText('10 = 10 minutes, 60 = an hour, 1440 = a day, 2880 = 2 days.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60 * 24 * 90)
                            ->required()
                            ->suffix('minutes'),

                        Select::make('color')
                            ->label('Badge colour')
                            ->options(ComplaintPriority::COLORS)
                            ->default('gray')
                            ->required(),

                        TextInput::make('sort_order')
                            ->label('Order')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Lower numbers appear first in the dropdown.'),

                        Toggle::make('is_active')
                            ->label('Available for new tickets')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
