<?php

namespace App\Filament\Resources\MisBatches;

use App\Filament\Resources\MisBatches\Pages\CreateMisBatch;
use App\Filament\Resources\MisBatches\Pages\EditMisBatch;
use App\Filament\Resources\MisBatches\Pages\ListMisBatches;
use App\Filament\Resources\MisBatches\Schemas\MisBatchForm;
use App\Filament\Resources\MisBatches\Tables\MisBatchesTable;
use App\Models\MisBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MisBatchResource extends Resource
{
    protected static ?string $model = MisBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'batch_name';

    public static function form(Schema $schema): Schema
    {
        return MisBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MisBatchesTable::configure($table);
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
            'index' => ListMisBatches::route('/'),
            'create' => CreateMisBatch::route('/create'),
            'edit' => EditMisBatch::route('/{record}/edit'),
        ];
    }
}
