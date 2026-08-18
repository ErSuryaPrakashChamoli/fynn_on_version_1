<?php

namespace App\Filament\Resources\CustomerSettlements;

use App\Filament\Resources\CustomerSettlements\Pages\CreateCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\EditCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\ListCustomerSettlements;
use App\Filament\Resources\CustomerSettlements\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\CustomerSettlements\Schemas\CustomerSettlementForm;
use App\Filament\Resources\CustomerSettlements\Tables\CustomerSettlementsTable;
use App\Models\CustomerSettlement;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class CustomerSettlementResource extends Resource
{
    protected static ?string $model = CustomerSettlement::class;
    protected static ?string $navigationLabel = 'Customer Settlement';
    protected static ?string $recordTitleAttribute = 'settlement_no';

    public static function form(Schema $schema): Schema
    {
        return CustomerSettlementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerSettlementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerSettlements::route('/'),
            'create' => CreateCustomerSettlement::route('/create'),
            'edit' => EditCustomerSettlement::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['Admin', 'Accounts']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
