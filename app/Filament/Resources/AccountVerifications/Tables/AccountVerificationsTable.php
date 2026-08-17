<?php

namespace App\Filament\Resources\AccountVerifications\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use App\Models\Customer;

class AccountVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //
                TextColumn::make('customer_name')
                    ->searchable(),

                TextColumn::make('mobile_no'),

                TextColumn::make('sanctioned_bank'),

                TextColumn::make('sanctioned_loan_amount')
                    ->money('INR'),

                TextColumn::make('cashback')
                    ->money('INR'),

                TextColumn::make('subvention')
                    ->money('INR'),

                TextColumn::make('docking')
                    ->money('INR'),

                TextColumn::make('created_at')
                    ->date(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Customer $record) {

                        $record->update([
                            'account_verified' => true,
                            'account_verified_by' => auth()->id(),
                            'account_verified_at' => now(),
                            'incentive_calculated' => false,
                        ]);
                    }),




            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
