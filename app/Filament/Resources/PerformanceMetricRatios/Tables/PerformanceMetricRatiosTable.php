<?php

namespace App\Filament\Resources\PerformanceMetricRatios\Tables;

use App\Support\Performance\MetricRegistry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PerformanceMetricRatiosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('numerator_key')
                    ->label('Numerator')
                    ->formatStateUsing(fn (string $state) => MetricRegistry::label($state))
                    ->badge()
                    ->sortable()
                    ->color('info'),

                TextColumn::make('denominator_key')
                    ->label('Denominator')
                    ->formatStateUsing(fn (string $state) => MetricRegistry::label($state))
                    ->badge()
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('format')
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('creator.emp_name')
                    ->label('Created By')
                    ->placeholder('System')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
