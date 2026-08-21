<?php

namespace App\Filament\Resources\OcrDocuments;

use App\Filament\Resources\OcrDocuments\Pages\CreateOcrDocument;
use App\Filament\Resources\OcrDocuments\Pages\EditOcrDocument;
use App\Filament\Resources\OcrDocuments\Pages\ListOcrDocuments;
use App\Filament\Resources\OcrDocuments\Pages\ViewOcrDocument;
use App\Filament\Resources\OcrDocuments\Schemas\OcrDocumentForm;
use App\Filament\Resources\OcrDocuments\Tables\OcrDocumentsTable;
use App\Models\OcrDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OcrDocumentResource extends Resource
{
    protected static ?string $model = OcrDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?string $navigationLabel = 'AI Documents';

    protected static ?string $modelLabel = 'AI Document';

    protected static ?string $pluralModelLabel = 'AI Documents';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return OcrDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OcrDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOcrDocuments::route('/'),
            'create' => CreateOcrDocument::route('/create'),
            'view' => ViewOcrDocument::route('/{record}'),
            'edit' => EditOcrDocument::route('/{record}/edit'),
        ];
    }
}
