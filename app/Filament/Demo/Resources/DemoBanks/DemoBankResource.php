<?php

namespace App\Filament\Demo\Resources\DemoBanks;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoBanks\Pages\ListDemoBanks;
use App\Filament\Demo\Resources\DemoBanks\Tables\DemoBanksTable;
use App\Models\Demo\DemoBank;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoBankResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoBank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Banks & NBFCs';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'banks';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return DemoBanksTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoBanks::route('/'),
        ];
    }
}
