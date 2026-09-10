<?php

namespace App\Filament\Academy\Resources\TrainingCourses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TrainingCourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Course')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('title')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                    'slug',
                                    Str::slug($state ?? ''),
                                ))
                                ->columnSpan(1),

                            TextInput::make('slug')
                                ->required()
                                ->maxLength(255)
                                ->helperText('Used in URLs. Unique within your organisation.')
                                ->columnSpan(1),

                            Textarea::make('summary')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),

                            Textarea::make('description')
                                ->rows(6)
                                ->columnSpanFull(),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Settings')
                    ->schema([
                        Grid::make(4)->schema([
                            Select::make('level')
                                ->options([
                                    'beginner' => 'Beginner',
                                    'intermediate' => 'Intermediate',
                                    'advanced' => 'Advanced',
                                ])
                                ->default('beginner')
                                ->required()
                                ->columnSpan(1),

                            Select::make('status')
                                ->options([
                                    'draft' => 'Draft',
                                    'published' => 'Published',
                                    'archived' => 'Archived',
                                ])
                                ->default('draft')
                                ->required()
                                ->columnSpan(1),

                            TextInput::make('duration_minutes')
                                ->label('Duration (minutes)')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->columnSpan(1),

                            TextInput::make('pass_percentage')
                                ->label('Pass %')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(60)
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
