<?php

namespace App\Filament\Demo\Resources\DemoApplications;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoApplications\Pages\ListDemoApplications;
use App\Filament\Demo\Resources\DemoApplications\Tables\DemoApplicationsTable;
use App\Models\Demo\DemoApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoApplicationResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Loan Applications';

    protected static ?string $modelLabel = 'application';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'applications';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return DemoApplicationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoApplications::route('/'),
        ];
    }
}
