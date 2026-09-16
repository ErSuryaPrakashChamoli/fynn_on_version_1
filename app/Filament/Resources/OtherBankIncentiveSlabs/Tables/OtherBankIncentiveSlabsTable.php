<?php

namespace App\Filament\Resources\OtherBankIncentiveSlabs\Tables;

use App\Models\OtherBankIncentiveSlab;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OtherBankIncentiveSlabsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('effective_month', 'desc')
            ->columns([
                TextColumn::make('effective_month')
                    ->label('Effective from')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('min_achievement')
                    ->label('Minimum achievement (₹)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => indianAmount($state))
                    ->sortable(),

                TextColumn::make('payout_type')
                    ->label('Payout type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OtherBankIncentiveSlab::payoutTypeOptions()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('payout_value')
                    ->label('Payout')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, OtherBankIncentiveSlab $record): string => $record->payoutLabel())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('payout_type')
                    ->label('Payout type')
                    ->options(OtherBankIncentiveSlab::payoutTypeOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
