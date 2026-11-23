<?php

namespace App\Filament\Resources\CustomerDataFields;

use App\Filament\Resources\CustomerDataFields\Pages\ManageCustomerDataFields;
use App\Filament\Resources\CustomerDataFields\Schemas\CustomerDataFieldForm;
use App\Filament\Resources\CustomerDataFields\Tables\CustomerDataFieldsTable;
use App\Models\CustomerDataField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * "Data Fields": the saved fields offered as tick boxes when customer data
 * is uploaded (AI Documents and Import Data). Replaces building a Data
 * Template per upload — rename, reorder or remove fields here.
 */
class CustomerDataFieldResource extends Resource
{
    protected static ?string $model = CustomerDataField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?string $navigationLabel = 'Data Fields';

    protected static ?string $modelLabel = 'Data Field';

    protected static ?string $pluralModelLabel = 'Data Fields';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return CustomerDataFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerDataFieldsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomerDataFields::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
