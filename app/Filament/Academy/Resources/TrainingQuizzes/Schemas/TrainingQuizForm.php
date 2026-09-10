<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes\Schemas;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingQuiz;
use App\Support\Portal\PortalContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TrainingQuizForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attach to')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('kind')
                            ->label('Type')
                            ->options([
                                TrainingQuiz::KIND_QUIZ => 'Lesson quiz',
                                TrainingQuiz::KIND_ASSESSMENT => 'Course assessment',
                            ])
                            ->default(TrainingQuiz::KIND_QUIZ)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                'quizzable_type',
                                $state === TrainingQuiz::KIND_ASSESSMENT
                                    ? TrainingCourse::class
                                    : TrainingLesson::class,
                            ))
                            ->columnSpan(1),

                        /*
                         * quizzable_type is derived from `kind` rather
                         * than chosen, so the two can never disagree —
                         * an "assessment" attached to a lesson would
                         * break TrainingQuiz::resolveCourse().
                         */
                        Select::make('quizzable_id')
                            ->label(fn (Get $get): string => $get('kind') === TrainingQuiz::KIND_ASSESSMENT
                                ? 'Course'
                                : 'Lesson')
                            ->options(fn (Get $get): array => $get('kind') === TrainingQuiz::KIND_ASSESSMENT
                                ? static::courseOptions()
                                : static::lessonOptions())
                            ->required()
                            ->preload()
                            ->columnSpan(1),

                        Select::make('quizzable_type')
                            ->options([
                                TrainingLesson::class => 'Lesson',
                                TrainingCourse::class => 'Course',
                            ])
                            ->default(TrainingLesson::class)
                            ->required()
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make('Details')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),

                    Grid::make(4)->schema([
                        TextInput::make('pass_percentage')
                            ->label('Pass %')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(60)
                            ->required(),

                        TextInput::make('time_limit_minutes')
                            ->label('Time limit (min)')
                            ->numeric()
                            ->minValue(1)
                            ->default(15),

                        TextInput::make('max_attempts')
                            ->label('Max attempts')
                            ->numeric()
                            ->minValue(1)
                            ->default(3)
                            ->required(),

                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                            ])
                            ->default('published')
                            ->required(),
                    ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function courseOptions(): array
    {
        return TrainingCourse::query()
            ->where('tenant_id', app(PortalContext::class)->tenantId())
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function lessonOptions(): array
    {
        return TrainingLesson::query()
            ->whereHas(
                'module.course',
                fn ($query) => $query->where('tenant_id', app(PortalContext::class)->tenantId())
            )
            ->with('module.course')
            ->get()
            ->mapWithKeys(fn (TrainingLesson $lesson): array => [
                $lesson->getKey() => $lesson->module?->course?->title.' — '.$lesson->title,
            ])
            ->all();
    }
}
