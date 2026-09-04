<?php

namespace App\Filament\Resources\AccountVerifications\Tables;

use App\Filament\Imports\MisSettlementImporter;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('application_no')->label('Application No')->searchable()->sortable(),
                TextColumn::make('lan_no')->label('LAN')->searchable()->sortable(),
                TextColumn::make('customer_name')->searchable()->sortable(),
                TextColumn::make('employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sanctioned_bank')->label('Bank')->searchable()->sortable(),
                TextColumn::make('settlement.sales_disbursal_amount')->label('Sales Loan')->money('INR')->sortable(),
                TextColumn::make('settlement.mis_disbursal_amount')->label('Bank Loan')->money('INR')->sortable(),
                TextColumn::make('settlement.variance_amount')->label('Loan Difference')->money('INR')->sortable(),
                TextColumn::make('settlement.status')->badge()->sortable(),
                TextColumn::make('settlement.achievement_difference')->label('Achievement Impact')->numeric()->sortable(),
                TextColumn::make('settlement.incentive_difference')->label('Incentive Impact')->money('INR')->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('settlement_status')
                    ->label('MIS Status')
                    ->options([
                        'pending' => 'Pending',
                        'mis_review' => 'MIS Review',
                        'mis_verified' => 'MIS Verified',
                    ])
                    ->query(function ($query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas('settlement', fn ($settlement) => $settlement->where('status', $data['value'])
                        );
                    }),
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
