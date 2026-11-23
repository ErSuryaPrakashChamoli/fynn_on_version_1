<?php

namespace App\Filament\Resources\CustomerDataFields\Tables;

use App\Models\CustomerDataField;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerDataFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('creator'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->description('The order here is the order fields are listed in when uploading. Drag to reorder.')
            ->columns([
                TextColumn::make('label')
                    ->label('Field name')
                    ->weight('medium')
                    ->description(fn (CustomerDataField $record): string => $record->key)
                    ->searchable(['label', 'key'])
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => CustomerDataField::TYPES[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('aliases')
                    ->label('Also known as')
                    ->badge()
                    ->color('info')
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->placeholder('-'),

                TextColumn::make('creator.name')
                    ->label('Added by')
                    ->placeholder('Built-in')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Added on')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Uploads will no longer offer this field. Data already uploaded keeps it.'),
            ]);
    }
}
