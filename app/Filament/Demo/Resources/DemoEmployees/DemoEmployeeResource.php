<?php

namespace App\Filament\Demo\Resources\DemoEmployees;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoEmployees\Pages\ListDemoEmployees;
use App\Filament\Demo\Resources\DemoEmployees\Tables\DemoEmployeesTable;
use App\Models\Demo\DemoEmployee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoEmployeeResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoEmployee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Employees';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'employees';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return DemoEmployeesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoEmployees::route('/'),
        ];
    }
}
