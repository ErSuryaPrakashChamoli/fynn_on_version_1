<?php

namespace App\Filament\Resources\CustomerSlaBreaches;

use App\Filament\Resources\CustomerSlaBreaches\Pages\ListCustomerSlaBreaches;
use App\Filament\Resources\CustomerSlaBreaches\Tables\CustomerSlaBreachesTable;
use App\Models\CustomerSlaBreach;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CustomerSlaBreachResource extends Resource
{
    protected static ?string $model = CustomerSlaBreach::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'SLA Breaches';

    protected static ?string $modelLabel = 'SLA Breach';

    protected static ?string $pluralModelLabel = 'SLA Breaches';

    public static function table(Table $table): Table
    {
        return CustomerSlaBreachesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerSlaBreaches::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']);
    }
}
