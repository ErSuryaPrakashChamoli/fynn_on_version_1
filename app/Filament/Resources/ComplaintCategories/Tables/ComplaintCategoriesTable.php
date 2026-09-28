<?php

namespace App\Filament\Resources\ComplaintCategories\Tables;

use App\Enums\ComplaintRouting;
use App\Models\ComplaintCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplaintCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['reasons', 'complaints']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Category')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ComplaintCategory $record): ?string => $record->description),

                TextColumn::make('routing')
                    ->label('Routed to')
                    ->badge()
                    ->formatStateUsing(fn (ComplaintRouting $state): string => $state === ComplaintRouting::Team ? 'Team' : 'Supervisor')
                    ->color(fn (ComplaintRouting $state): string => $state === ComplaintRouting::Team ? 'info' : 'warning')
                    ->sortable(),

                TextColumn::make('handler_roles')
                    ->label('Handling roles')
                    ->badge()
                    ->placeholder('Chosen by the user')
                    ->separator(','),

                TextColumn::make('reasons_count')
                    ->label('Reasons')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('reasons_count', $direction)),

                TextColumn::make('complaints_count')
                    ->label('Tickets')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('complaints_count', $direction)),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('routing')
                    ->options(ComplaintRouting::options()),

                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
