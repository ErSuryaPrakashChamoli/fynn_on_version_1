<?php

namespace App\Filament\Demo\Resources\DemoLoanProducts\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DemoLoanProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('applications'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('code')
                    ->badge(),

                TextColumn::make('min_amount')
                    ->label('From')
                    ->money('INR', 100)
                    ->alignEnd(),

                TextColumn::make('max_amount')
                    ->label('Up to')
                    ->money('INR', 100)
                    ->alignEnd(),

                TextColumn::make('tenure')
                    ->label('Tenure')
                    ->state(fn ($record): string => "{$record->min_tenure_months}–{$record->max_tenure_months} months")
                    ->alignEnd(),

                TextColumn::make('interest_rate_from')
                    ->label('ROI from')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('name');
    }
}
