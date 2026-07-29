<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use App\Models\Employee;
use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Actions\Action;


class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //
                 TextColumn::make('application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lan_no')
                    ->label('LAN No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Customer Name')
                    // ->searchable()
                    ->searchable(isIndividual: true , isGlobal: false)
                    ->sortable(),

                TextColumn::make('sanctioned_loan_amount')
                    ->label('Loan Amount')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('mobile_no')
                    ->label('Mobile No')
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return substr($state, 0, 4) . 'XXXXXX';
                    })
                    ->searchable(),

                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('loan_applied')
                    ->label('Loan Applied')
                    ->searchable(),

                TextColumn::make('salary')
                    ->label('Salary')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('eligibility_status')
                    ->label('Eligibility')
                    ->badge(),

                TextColumn::make('bank_eligible_for')
                    ->label('Bank Eligible For')
                    ->formatStateUsing(function ($state, $record) {
                        return strtolower((string) $state) === 'other'
                            ? ($record->other_bank_eligible_for ?: '-')
                            : $state;
                    })
                    ->searchable(),

                TextColumn::make('journey_status')
                    ->label('Journey')
                    ->badge(),

                TextColumn::make('sanctioned_bank')
                    ->label('Bank')
                    ->searchable(),

                TextColumn::make('channel')
                    ->label('Channel')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                // EditAction::make(),
                EditAction::make()
                    ->visible(
                        fn($record) =>
                        // ! $record->documents_submitted &&
                            auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
                    ),

                Action::make('followup')
                        ->label('Follow Up')
                        ->icon('heroicon-o-phone')
                        ->color('warning')
                        ->url(fn($record) => FollowUpResource::getUrl('create', [
                            'customer' => $record->id,
                        ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                DeleteBulkAction::make(),
                ]),
            ]);
    }
}
