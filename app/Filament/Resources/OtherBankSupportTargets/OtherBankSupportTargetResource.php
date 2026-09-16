<?php

namespace App\Filament\Resources\OtherBankSupportTargets;

use App\Filament\Resources\OtherBankSupportTargets\Pages\CreateOtherBankSupportTarget;
use App\Filament\Resources\OtherBankSupportTargets\Pages\EditOtherBankSupportTarget;
use App\Filament\Resources\OtherBankSupportTargets\Pages\ListOtherBankSupportTargets;
use App\Filament\Resources\OtherBankSupportTargets\Schemas\OtherBankSupportTargetForm;
use App\Filament\Resources\OtherBankSupportTargets\Tables\OtherBankSupportTargetsTable;
use App\Models\OtherBankSupportTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin-set monthly targets for the Other Bank Support team. Separate from
 * the LMS target engine and the Daily Commitment module's monthly targets.
 */
class OtherBankSupportTargetResource extends Resource
{
    protected static ?string $model = OtherBankSupportTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Other Bank Support';

    protected static ?string $navigationLabel = 'Support Targets';

    protected static ?string $modelLabel = 'support target';

    protected static ?string $pluralModelLabel = 'support targets';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return OtherBankSupportTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OtherBankSupportTargetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOtherBankSupportTargets::route('/'),
            'create' => CreateOtherBankSupportTarget::route('/create'),
            'edit' => EditOtherBankSupportTarget::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
