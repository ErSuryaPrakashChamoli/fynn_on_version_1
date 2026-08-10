<?php

namespace App\Filament\Resources\CustomerPanRequests;

use App\Filament\Resources\CustomerPanRequests\Pages\CreateCustomerPanRequest;
use App\Filament\Resources\CustomerPanRequests\Pages\EditCustomerPanRequest;
use App\Filament\Resources\CustomerPanRequests\Pages\ListCustomerPanRequests;
use App\Filament\Resources\CustomerPanRequests\Schemas\CustomerPanRequestForm;
use App\Filament\Resources\CustomerPanRequests\Tables\CustomerPanRequestsTable;
use App\Models\CustomerPanRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
// use continuation\ContinuePanRequest;
use UnitEnum;

class CustomerPanRequestResource extends Resource
{
    protected static ?string $model = CustomerPanRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'customer_id';

    protected static string | UnitEnum | null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Duplicate PAN Request';

    protected static ?string $modelLabel = 'PAN Request';

    public static function form(Schema $schema): Schema
    {
        return CustomerPanRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerPanRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerPanRequests::route('/'),
            'create' => CreateCustomerPanRequest::route('/create'),
            'edit' => EditCustomerPanRequest::route('/{record}/edit'),
            // 'continue-pan' => ContinuePanRequest::route(
            //     '/continue-pan/{request}'
            // ),
        ];
    }
}
