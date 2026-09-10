<?php

namespace App\Filament\Demo\Resources\DemoLeads;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoLeads\Pages\ListDemoLeads;
use App\Filament\Demo\Resources\DemoLeads\Tables\DemoLeadsTable;
use App\Models\Demo\DemoLead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoLeadResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Leads';

    protected static ?string $modelLabel = 'lead';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'leads';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return DemoLeadsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoLeads::route('/'),
        ];
    }
}
