<?php

namespace App\Filament\Academy\Resources\TrainingLessons;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingLessons\Pages\CreateTrainingLesson;
use App\Filament\Academy\Resources\TrainingLessons\Pages\EditTrainingLesson;
use App\Filament\Academy\Resources\TrainingLessons\Pages\ListTrainingLessons;
use App\Filament\Academy\Resources\TrainingLessons\RelationManagers\DocumentsRelationManager;
use App\Filament\Academy\Resources\TrainingLessons\RelationManagers\QuizzesRelationManager;
use App\Filament\Academy\Resources\TrainingLessons\Schemas\TrainingLessonForm;
use App\Filament\Academy\Resources\TrainingLessons\Tables\TrainingLessonsTable;
use App\Models\Training\TrainingLesson;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TrainingLessonResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingLesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Lessons';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'lessons';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return TrainingLessonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingLessonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            QuizzesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingLessons::route('/'),
            'create' => CreateTrainingLesson::route('/create'),
            'edit' => EditTrainingLesson::route('/{record}/edit'),
        ];
    }

    /**
     * Two relations up to the tenant: lesson -> module -> course.
     */
    protected static function scopeQueryToTenant(Builder $query): Builder
    {
        return $query->whereHas(
            'module.course',
            fn (Builder $courseQuery) => $courseQuery->where('tenant_id', static::currentTenantId())
        );
    }
}
