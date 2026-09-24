<?php

namespace App\Filament\Demo\Resources\DemoCustomers;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoCustomers\Pages\CreateDemoCustomer;
use App\Filament\Demo\Resources\DemoCustomers\Pages\EditDemoCustomer;
use App\Filament\Demo\Resources\DemoCustomers\Pages\ListDemoCustomers;
use App\Filament\Demo\Resources\DemoCustomers\Schemas\DemoCustomerForm;
use App\Filament\Demo\Resources\DemoCustomers\Tables\DemoCustomersTable;
use App\Models\Demo\DemoCustomer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoCustomerResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $modelLabel = 'customer';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'customers';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return DemoCustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DemoCustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoCustomers::route('/'),
            'create' => CreateDemoCustomer::route('/create'),
            'edit' => EditDemoCustomer::route('/{record}/edit'),
        ];
    }
}
