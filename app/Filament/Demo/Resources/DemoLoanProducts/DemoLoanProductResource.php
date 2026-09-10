<?php

namespace App\Filament\Demo\Resources\DemoLoanProducts;

use App\Filament\Demo\Resources\Concerns\SandboxResource;
use App\Filament\Demo\Resources\DemoLoanProducts\Pages\ListDemoLoanProducts;
use App\Filament\Demo\Resources\DemoLoanProducts\Tables\DemoLoanProductsTable;
use App\Models\Demo\DemoLoanProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DemoLoanProductResource extends Resource
{
    use SandboxResource;

    protected static ?string $model = DemoLoanProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Loan Products';

    /*
     * A product URL, not a table name. A prospect should see
     * /demo/leads — the sandbox is presented as FYNN-ON itself, and
     * "demo-leads" in the address bar gives away the implementation.
     */
    protected static ?string $slug = 'loan-products';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return DemoLoanProductsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoLoanProducts::route('/'),
        ];
    }
}
