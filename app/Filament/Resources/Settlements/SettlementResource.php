<?php

namespace App\Filament\Resources\Settlements;

use App\Filament\Resources\Settlements\Pages\CreateSettlement;
use App\Filament\Resources\Settlements\Pages\EditSettlement;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\Schemas\SettlementForm;
use App\Filament\Resources\Settlements\Tables\SettlementsTable;
use App\Models\Settlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use App\Models\CustomerSettlement;

class SettlementResource extends Resource
{
    // protected static ?string $model = Settlement::class;
    protected static ?string $model = CustomerSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'customer_id';

    public static function form(Schema $schema): Schema
    {
        return SettlementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SettlementsTable::configure($table);
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
            'index' => ListSettlements::route('/'),
            'create' => CreateSettlement::route('/create'),
            'edit' => EditSettlement::route('/{record}/edit'),
        ];
    }
}
