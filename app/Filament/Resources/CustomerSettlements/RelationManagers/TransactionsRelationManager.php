<?php

namespace App\Filament\Resources\CustomerSettlements\RelationManagers;

use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementTransaction;
use App\Services\Settlement\SettlementTransactionService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Financial Transactions';

    public static function canViewForRecord(
        Model $ownerRecord,
        string $pageClass
    ): bool {
        return auth()->user()?->hasAnyRole(['Admin', 'Accounts']) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('type')
                ->options([
                    'payment' => 'Payment',
                    'advance' => 'Advance',
                    'recovery' => 'Recovery',
                    'adjustment' => 'Adjustment',
                    'refund' => 'Refund',
                ])
                ->required(),

            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->minValue(0.01),

            Forms\Components\DatePicker::make('transaction_date')
                ->default(now())
                ->required(),

            Forms\Components\TextInput::make('reference')
                ->label('Reference')
                ->maxLength(255),

            Forms\Components\TextInput::make('utr_number')
                ->maxLength(255),

            Forms\Components\Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'received' => 'Received',
                    'adjusted' => 'Adjusted',
                    'reversed' => 'Reversed',
                ])
                ->default('received'),

            Forms\Components\Textarea::make('remarks')
                ->columnSpanFull(),

            Forms\Components\Hidden::make('created_by')
                ->default(fn () => auth()->id()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('transaction_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('utr_number')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->searchable()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (CustomerSettlementTransaction $record): void {
                        app(SettlementTransactionService::class)->sync(
                            $record->customerSettlement()->firstOrFail()
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (CustomerSettlementTransaction $record): void {
                        app(SettlementTransactionService::class)->sync(
                            $record->customerSettlement()->firstOrFail()
                        );
                    }),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('deletion_reason')
                            ->label('Reason for deletion')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->before(function (CustomerSettlementTransaction $record): void {
                        session()->put(
                            'deleted_settlement_transaction',
                            $record->only([
                                'customer_settlement_id',
                                'type',
                                'amount',
                                'transaction_date',
                                'reference',
                                'utr_number',
                            ])
                        );
                    })
                    ->after(function () {
                        $settlementId = session()->pull(
                            'deleted_settlement_transaction.customer_settlement_id'
                        );

                        if ($settlementId) {
                            $settlement = CustomerSettlement::find($settlementId);

                            if ($settlement) {
                                app(SettlementTransactionService::class)->sync($settlement);
                            }
                        }
                    }),
            ]);
    }
}
