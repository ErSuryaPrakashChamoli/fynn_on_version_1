<?php

namespace App\Filament\Resources\CustomerSettlements;

use App\Filament\Resources\CustomerSettlements\Pages\CreateCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\EditCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\ListCustomerSettlements;
use App\Filament\Resources\CustomerSettlements\Schemas\CustomerSettlementForm;
use App\Filament\Resources\CustomerSettlements\Tables\CustomerSettlementsTable;
use App\Models\CustomerSettlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CustomerSettlementResource extends Resource
{
    protected static ?string $model = CustomerSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerSettlements::route('/'),
            'create' => CreateCustomerSettlement::route('/create'),
            'edit' => EditCustomerSettlement::route('/{record}/edit'),
        ];
    }
}
