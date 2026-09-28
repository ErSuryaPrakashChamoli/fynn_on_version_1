<?php

namespace App\Filament\Resources\PollTypes\Tables;

use App\Models\PollType;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PollTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('polls'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Type')
                    ->searchable()
                    ->sortable()
                    ->description(fn (PollType $record): ?string => $record->description),

                TextColumn::make('options')
                    ->label('Options')
                    ->badge()
                    ->state(fn (PollType $record): array => $record->optionList()),

                IconColumn::make('allow_comment')
                    ->label('Comment')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('polls_count')
                    ->label('Polls')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('polls_count', $direction)),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
