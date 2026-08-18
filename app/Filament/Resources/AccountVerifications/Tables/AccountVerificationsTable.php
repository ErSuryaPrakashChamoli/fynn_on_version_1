<?php

namespace App\Filament\Resources\AccountVerifications\Tables;

use App\Filament\Imports\MisSettlementImporter;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('application_no')->label('Application No')->searchable(),
                TextColumn::make('lan_no')->label('LAN')->searchable(),
                TextColumn::make('customer_name')->searchable(),
                TextColumn::make('sanctioned_bank')->label('Bank'),
                TextColumn::make('settlement.sales_disbursal_amount')->label('Sales Loan')->money('INR'),
                TextColumn::make('settlement.mis_disbursal_amount')->label('Bank Loan')->money('INR'),
                TextColumn::make('settlement.variance_amount')->label('Loan Difference')->money('INR'),
                TextColumn::make('settlement.status')->badge(),
                TextColumn::make('updated_at')->dateTime(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->headerActions([
                ImportAction::make()
                    ->label('Import Bank MIS')
                    ->importer(MisSettlementImporter::class)
                    ->visible(fn () => auth()->user()?->hasAnyRole(['Admin', 'MIS'])),
            ]);
    }
}
