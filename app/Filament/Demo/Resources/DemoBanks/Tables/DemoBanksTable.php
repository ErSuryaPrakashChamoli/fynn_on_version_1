<?php

namespace App\Filament\Demo\Resources\DemoBanks\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoBanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('applications'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('short_name')
                    ->label('Code')
                    ->badge(),

                TextColumn::make('type')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->sortable(),

                TextColumn::make('min_interest_rate')
                    ->label('ROI from')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('min_salary')
                    ->label('Min. salary')
                    ->money('INR', 100)
                    ->alignEnd(),

                TextColumn::make('payout_rate')
                    ->label('Payout')
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
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'private' => 'Private Bank',
                        'public' => 'Public Sector Bank',
                        'nbfc' => 'NBFC',
                        'sfb' => 'Small Finance Bank',
                        'hfc' => 'Housing Finance',
                        'cooperative' => 'Cooperative Bank',
                    ]),
            ])
            ->defaultSort('name');
    }
}
