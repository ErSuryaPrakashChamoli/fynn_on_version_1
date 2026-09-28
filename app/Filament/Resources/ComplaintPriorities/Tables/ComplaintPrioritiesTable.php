<?php

namespace App\Filament\Resources\ComplaintPriorities\Tables;

use App\Models\ComplaintPriority;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplaintPrioritiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('complaints'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (ComplaintPriority $record): string => $record->color)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('resolve_within_minutes')
                    ->label('Resolve within')
                    ->formatStateUsing(fn (ComplaintPriority $record): string => $record->slaLabel().' ('.$record->resolve_within_minutes.' min)')
                    ->sortable(),

                TextColumn::make('complaints_count')
                    ->label('Tickets')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('complaints_count', $direction)),

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
