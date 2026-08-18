<?php

namespace App\Filament\Resources\CustomerSettlements\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';
    protected static ?string $title = 'Payment / Recovery / Advance History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->options([
                'payment' => 'Payment',
                'advance' => 'Advance',
                'recovery' => 'Recovery',
                'adjustment' => 'Adjustment',
                'refund' => 'Refund',
                'surplus' => 'Surplus',
            ])->required(),
            TextInput::make('amount')->numeric()->required(),
            DatePicker::make('transaction_date'),
            TextInput::make('reference_no'),
            Textarea::make('remarks')->rows(3),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')->date(),
                TextColumn::make('type')->badge(),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('reference_no'),
                TextColumn::make('createdBy.name')->label('Entered By'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn(array $data): array => [
                        ...$data,
                        'created_by' => auth()->id(),
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function canViewForRecord(
        Model $ownerRecord,
        string $pageClass
    ): bool {
        return auth()->user()?->hasAnyRole(['Admin', 'Accounts']) ?? false;
    }
}
