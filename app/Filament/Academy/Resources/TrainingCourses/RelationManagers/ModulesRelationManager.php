<?php

namespace App\Filament\Academy\Resources\TrainingCourses\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Modules are managed inside their course, which is what keeps a module
 * from ever being created without one.
 */
class ModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Modules';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->required(),

            Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                ])
                ->default('published')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->alignCenter(),

                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('lessons_count')
                    ->label('Lessons')
                    ->counts('lessons')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
