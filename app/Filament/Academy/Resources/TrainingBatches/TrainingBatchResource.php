<?php

namespace App\Filament\Academy\Resources\TrainingBatches;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingBatches\Pages\CreateTrainingBatch;
use App\Filament\Academy\Resources\TrainingBatches\Pages\EditTrainingBatch;
use App\Filament\Academy\Resources\TrainingBatches\Pages\ListTrainingBatches;
use App\Filament\Academy\Resources\TrainingBatches\RelationManagers\SessionsRelationManager;
use App\Filament\Academy\Resources\TrainingBatches\RelationManagers\TraineesRelationManager;
use App\Filament\Academy\Resources\TrainingBatches\Schemas\TrainingBatchForm;
use App\Filament\Academy\Resources\TrainingBatches\Tables\TrainingBatchesTable;
use App\Models\Training\TrainingBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainingBatchResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?string $navigationLabel = 'Batches';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'batches';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return TrainingBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TraineesRelationManager::class,
            SessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingBatches::route('/'),
            'create' => CreateTrainingBatch::route('/create'),
            'edit' => EditTrainingBatch::route('/{record}/edit'),
        ];
    }
}
