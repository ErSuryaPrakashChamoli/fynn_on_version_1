<?php

namespace App\Filament\Demo\Resources\DemoApplications\Tables;

use App\Models\Demo\DemoApplication;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'bank', 'loanProduct', 'employee']))
            ->columns([
                TextColumn::make('application_no')
                    ->label('Application')
                    ->searchable()
                    ->fontFamily('mono'),

                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('bank.name')
                    ->label('Lender')
                    ->searchable()
                    ->limit(22),

                TextColumn::make('loanProduct.name')
                    ->label('Product')
                    ->badge()
                    ->color('info'),

                TextColumn::make('applied_amount')
                    ->label('Applied')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('sanctioned_amount')
                    ->label('Sanctioned')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('INR', 100)),

                TextColumn::make('disbursed_amount')
                    ->label('Disbursed')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->money('INR', 100)),

                TextColumn::make('interest_rate')
                    ->label('ROI')
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'disbursed' => 'success',
                        'sanctioned' => 'info',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => DemoApplication::STATUSES[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('disbursed_on')
                    ->label('Disbursed on')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DemoApplication::STATUSES),

                SelectFilter::make('demo_bank_id')
                    ->label('Lender')
                    ->relationship('bank', 'name'),

                SelectFilter::make('demo_loan_product_id')
                    ->label('Product')
                    ->relationship('loanProduct', 'name'),

                SelectFilter::make('demo_employee_id')
                    ->label('Owner')
                    ->relationship('employee', 'name'),
            ])
            ->defaultSort('applied_on', 'desc');
    }
}
