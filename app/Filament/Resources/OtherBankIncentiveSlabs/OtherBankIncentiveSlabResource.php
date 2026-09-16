<?php

namespace App\Filament\Resources\OtherBankIncentiveSlabs;

use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\CreateOtherBankIncentiveSlab;
use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\EditOtherBankIncentiveSlab;
use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\ListOtherBankIncentiveSlabs;
use App\Filament\Resources\OtherBankIncentiveSlabs\Schemas\OtherBankIncentiveSlabForm;
use App\Filament\Resources\OtherBankIncentiveSlabs\Tables\OtherBankIncentiveSlabsTable;
use App\Models\OtherBankIncentiveSlab;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin-defined incentive ladder for the Other Bank Support team. Never read
 * by AchievementCalculatorService's LMS incentive slabs.
 */
class OtherBankIncentiveSlabResource extends Resource
{
    protected static ?string $model = OtherBankIncentiveSlab::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyRupee;

    protected static string|UnitEnum|null $navigationGroup = 'Other Bank Support';

    protected static ?string $navigationLabel = 'Incentive Slabs';

    protected static ?string $modelLabel = 'incentive slab';

    protected static ?string $pluralModelLabel = 'incentive slabs';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return OtherBankIncentiveSlabForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OtherBankIncentiveSlabsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOtherBankIncentiveSlabs::route('/'),
            'create' => CreateOtherBankIncentiveSlab::route('/create'),
            'edit' => EditOtherBankIncentiveSlab::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
