<?php

namespace App\Filament\Demo\Resources\DemoFollowUps;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoFollowUps\Pages\ListDemoFollowUps;
use App\Filament\Demo\Resources\DemoFollowUps\Tables\DemoFollowUpsTable;
use App\Models\Demo\DemoFollowUp;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoFollowUpResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoFollowUp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Follow-ups';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'follow-ups';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return DemoFollowUpsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoFollowUps::route('/'),
        ];
    }
}
