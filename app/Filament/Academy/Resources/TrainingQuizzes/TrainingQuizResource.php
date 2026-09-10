<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingQuizzes\Pages\CreateTrainingQuiz;
use App\Filament\Academy\Resources\TrainingQuizzes\Pages\EditTrainingQuiz;
use App\Filament\Academy\Resources\TrainingQuizzes\Pages\ListTrainingQuizzes;
use App\Filament\Academy\Resources\TrainingQuizzes\RelationManagers\QuestionsRelationManager;
use App\Filament\Academy\Resources\TrainingQuizzes\Schemas\TrainingQuizForm;
use App\Filament\Academy\Resources\TrainingQuizzes\Tables\TrainingQuizzesTable;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingQuiz;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Quizzes and final assessments together — they are the same table (see
 * the training_quizzes migration), so one resource manages both and the
 * `kind` column is what the trainer picks.
 */
class TrainingQuizResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingQuiz::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Assessment';

    protected static ?string $navigationLabel = 'Quizzes & Assessments';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'assessments';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return TrainingQuizForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingQuizzesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingQuizzes::route('/'),
            'create' => CreateTrainingQuiz::route('/create'),
            'edit' => EditTrainingQuiz::route('/{record}/edit'),
        ];
    }

    /**
     * The owner is polymorphic, so the tenant constraint has to be
     * expressed as "belongs to a course in my tenant, whichever end it
     * hangs off". Written as an explicit whereIn over the two owner
     * types rather than a morph relation query, because a morphTo cannot
     * be constrained with whereHas.
     */
    protected static function scopeQueryToTenant(Builder $query): Builder
    {
        $tenantId = static::currentTenantId();

        return $query->where(function (Builder $outer) use ($tenantId): void {
            $outer
                ->where(function (Builder $inner) use ($tenantId): void {
                    $inner
                        ->where('quizzable_type', TrainingCourse::class)
                        ->whereIn('quizzable_id', TrainingCourse::query()
                            ->where('tenant_id', $tenantId)
                            ->select('id'));
                })
                ->orWhere(function (Builder $inner) use ($tenantId): void {
                    $inner
                        ->where('quizzable_type', TrainingLesson::class)
                        ->whereIn('quizzable_id', TrainingLesson::query()
                            ->whereHas(
                                'module.course',
                                fn (Builder $course) => $course->where('tenant_id', $tenantId)
                            )
                            ->select('id'));
                });
        });
    }
}
