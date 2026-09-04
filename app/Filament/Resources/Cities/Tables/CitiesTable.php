<?php

namespace App\Filament\Resources\Cities\Tables;

use App\Models\City;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //

                TextColumn::make('id')
                    ->sortable(),

                TextColumn::make('country')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('state')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('state_code')
                    ->label('State Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('city_code')
                    ->label('City Code')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('country')
                    ->label('Country')
                    ->multiple()
                    ->options(fn (): array => City::query()->distinct()->orderBy('country')->pluck('country', 'country')->all()),

                SelectFilter::make('state')
                    ->label('State')
                    ->multiple()
                    ->options(fn (): array => City::query()->distinct()->orderBy('state')->pluck('state', 'state')->all()),

                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                // EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
