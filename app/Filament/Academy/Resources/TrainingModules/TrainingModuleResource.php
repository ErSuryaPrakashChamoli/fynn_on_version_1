<?php

namespace App\Filament\Academy\Resources\TrainingModules;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingModules\Pages\CreateTrainingModule;
use App\Filament\Academy\Resources\TrainingModules\Pages\EditTrainingModule;
use App\Filament\Academy\Resources\TrainingModules\Pages\ListTrainingModules;
use App\Filament\Academy\Resources\TrainingModules\Schemas\TrainingModuleForm;
use App\Filament\Academy\Resources\TrainingModules\Tables\TrainingModulesTable;
use App\Models\Training\TrainingModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * A flat, cross-course view of every module.
 *
 * Modules are normally edited inside their course (see
 * ModulesRelationManager); this resource exists because a trainer
 * running several courses needs one list to reorder and re-status from.
 */
class TrainingModuleResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingModule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Modules';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'modules';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return TrainingModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingModulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingModules::route('/'),
            'create' => CreateTrainingModule::route('/create'),
            'edit' => EditTrainingModule::route('/{record}/edit'),
        ];
    }

    /**
     * Modules carry no tenant_id of their own, so the constraint is
     * applied through the course they belong to.
     */
    protected static function scopeQueryToTenant(Builder $query): Builder
    {
        return $query->whereHas(
            'course',
            fn (Builder $courseQuery) => $courseQuery->where('tenant_id', static::currentTenantId())
        );
    }
}
