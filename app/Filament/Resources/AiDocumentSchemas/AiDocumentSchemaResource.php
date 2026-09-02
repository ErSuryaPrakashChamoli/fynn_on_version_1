<?php

namespace App\Filament\Resources\AiDocumentSchemas;

use App\Filament\Resources\AiDocumentSchemas\Pages\CreateAiDocumentSchema;
use App\Filament\Resources\AiDocumentSchemas\Pages\EditAiDocumentSchema;
use App\Filament\Resources\AiDocumentSchemas\Pages\ListAiDocumentSchemas;
use App\Filament\Resources\AiDocumentSchemas\Schemas\AiDocumentSchemaForm;
use App\Filament\Resources\AiDocumentSchemas\Tables\AiDocumentSchemasTable;
use App\Models\AiDocumentSchema;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiDocumentSchemaResource extends Resource
{
    protected static ?string $model = AiDocumentSchema::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;
    protected static string|UnitEnum|null $navigationGroup = 'Documents';
    protected static ?string $navigationLabel = 'Data Templates';
    protected static ?string $modelLabel = 'Data Template';
    protected static ?string $pluralModelLabel = 'Data Templates';
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AiDocumentSchemaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiDocumentSchemasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiDocumentSchemas::route('/'),
            'create' => CreateAiDocumentSchema::route('/create'),
            'edit' => EditAiDocumentSchema::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
