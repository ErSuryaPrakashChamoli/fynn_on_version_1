<?php

namespace App\Filament\Resources\CustomerSettlements\Tables;

use App\Models\CustomerSettlement;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerSettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('settlement_no')->searchable()->sortable(),
                TextColumn::make('customer.application_no')->label('Application No')->searchable()->sortable(),
                TextColumn::make('customer.lan_no')->label('LAN')->searchable()->sortable(),
                TextColumn::make('customer.customer_name')->label('Customer')->searchable()->sortable(),
                TextColumn::make('customer.employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->searchable(),
                TextColumn::make('customer.employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('sales_disbursal_amount')->label('Sales Loan')->money('INR')->sortable(),
                TextColumn::make('mis_disbursal_amount')->label('Bank Loan')->money('INR')->sortable(),
                TextColumn::make('variance_amount')->label('Loan Difference')->money('INR')->sortable(),
                TextColumn::make('recovery_pending')->label('Recovery Pending')->money('INR')->sortable(),
                TextColumn::make('advance_outstanding')->label('Advance Outstanding')->money('INR')->sortable(),
                TextColumn::make('outstanding_amount')->label('Outstanding')->money('INR')->sortable(),
                TextColumn::make('surplus_amount')->label('Surplus')->money('INR')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('payment_status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(fn (): array => CustomerSettlement::query()
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),

                SelectFilter::make('payment_status')
                    ->label('Payment Status')
                    ->multiple()
                    ->options(fn (): array => CustomerSettlement::query()
                        ->whereNotNull('payment_status')
                        ->distinct()
                        ->orderBy('payment_status')
                        ->pluck('payment_status', 'payment_status')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
