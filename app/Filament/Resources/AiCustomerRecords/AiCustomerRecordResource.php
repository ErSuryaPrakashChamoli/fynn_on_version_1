<?php

namespace App\Filament\Resources\AiCustomerRecords;

use App\Filament\Resources\AiCustomerRecords\Pages\EditAiCustomerRecord;
use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Filament\Resources\AiCustomerRecords\Pages\ViewAiCustomerRecord;
use App\Filament\Resources\AiCustomerRecords\Schemas\AiCustomerRecordForm;
use App\Filament\Resources\AiCustomerRecords\Tables\AiCustomerRecordsTable;
use App\Models\AiCustomerRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AiCustomerRecordResource extends Resource
{
    protected static ?string $model = AiCustomerRecord::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;
    protected static string|UnitEnum|null $navigationGroup = 'Documents';
    protected static ?string $navigationLabel = 'Customer Data';
    protected static ?string $modelLabel = 'Customer Data Row';
    protected static ?string $pluralModelLabel = 'Customer Data';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return AiCustomerRecordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiCustomerRecordsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiCustomerRecords::route('/'),
            'view' => ViewAiCustomerRecord::route('/{record}'),
            'edit' => EditAiCustomerRecord::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
