<?php

namespace App\Filament\Academy\Resources\TrainingCourses;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingCourses\Pages\CreateTrainingCourse;
use App\Filament\Academy\Resources\TrainingCourses\Pages\EditTrainingCourse;
use App\Filament\Academy\Resources\TrainingCourses\Pages\ListTrainingCourses;
use App\Filament\Academy\Resources\TrainingCourses\RelationManagers\ModulesRelationManager;
use App\Filament\Academy\Resources\TrainingCourses\Schemas\TrainingCourseForm;
use App\Filament\Academy\Resources\TrainingCourses\Tables\TrainingCoursesTable;
use App\Models\Training\TrainingCourse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainingCourseResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingCourse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Courses';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'courses';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return TrainingCourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingCoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ModulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingCourses::route('/'),
            'create' => CreateTrainingCourse::route('/create'),
            'edit' => EditTrainingCourse::route('/{record}/edit'),
        ];
    }
}
