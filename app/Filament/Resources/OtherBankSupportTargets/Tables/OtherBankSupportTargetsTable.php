<?php

namespace App\Filament\Resources\OtherBankSupportTargets\Tables;

use App\Models\OtherBankSupportTarget;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OtherBankSupportTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Support user')
                    ->description(fn (OtherBankSupportTarget $record): ?string => $record->user?->email)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('month')
                    ->label('Month')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('target_amount')
                    ->label('Target (₹)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => indianAmount($state))
                    ->description(fn (OtherBankSupportTarget $record): string => indianAmountInWords($record->target_amount))
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime('d M Y, H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Support user')
                    ->relationship('user', 'name'),

                Filter::make('current_month')
                    ->label('This month only')
                    ->query(fn (Builder $query): Builder => $query->whereDate('month', Carbon::today()->startOfMonth()->toDateString())),
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
