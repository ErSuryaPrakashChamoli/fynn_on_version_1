<?php

namespace App\Filament\Resources\CustomerSettlements\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerSettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('settlement_no')->searchable(),
                TextColumn::make('customer.application_no')->label('Application No')->searchable(),
                TextColumn::make('customer.lan_no')->label('LAN')->searchable(),
                TextColumn::make('customer.customer_name')->label('Customer')->searchable(),
                TextColumn::make('sales_disbursal_amount')->label('Sales Loan')->money('INR'),
                TextColumn::make('mis_disbursal_amount')->label('Bank Loan')->money('INR'),
                TextColumn::make('variance_amount')->label('Loan Difference')->money('INR'),
                TextColumn::make('recovery_pending')->label('Recovery Pending')->money('INR'),
                TextColumn::make('advance_outstanding')->label('Advance Outstanding')->money('INR'),
                TextColumn::make('outstanding_amount')->label('Outstanding')->money('INR'),
                TextColumn::make('surplus_amount')->label('Surplus')->money('INR'),
                TextColumn::make('status')->badge(),
                TextColumn::make('payment_status')->badge(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
