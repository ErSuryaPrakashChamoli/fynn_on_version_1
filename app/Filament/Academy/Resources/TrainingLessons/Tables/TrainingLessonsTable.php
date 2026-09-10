<?php

namespace App\Filament\Academy\Resources\TrainingLessons\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TrainingLessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('module.course.title')
                    ->label('Course')
                    ->limit(28)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('module.title')
                    ->label('Module')
                    ->limit(24)
                    ->searchable(),

                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('content_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),

                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => $state.' min')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('content_type')
                    ->label('Type')
                    ->options([
                        'text' => 'Reading material',
                        'video' => 'Video',
                        'mixed' => 'Video + reading',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                    ]),

                TernaryFilter::make('is_required')
                    ->label('Required'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
