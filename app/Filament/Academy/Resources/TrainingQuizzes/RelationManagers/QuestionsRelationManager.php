<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes\RelationManagers;

use App\Models\Training\TrainingQuizQuestion;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The question bank for one quiz.
 *
 * Options are a key => text map (A, B, C, D) and correct_answer stores
 * the KEY, so editing an option's wording never silently changes which
 * answer is right.
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Questions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            Select::make('type')
                ->options([
                    'single_choice' => 'Single choice',
                    'multiple_choice' => 'Multiple choice',
                    'true_false' => 'True / False',
                ])
                ->default('single_choice')
                ->required(),

            TextInput::make('marks')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),

            KeyValue::make('options')
                ->label('Options')
                ->keyLabel('Key')
                ->valueLabel('Answer text')
                ->default(['A' => '', 'B' => '', 'C' => '', 'D' => ''])
                ->required()
                ->columnSpanFull(),

            /*
             * Stored as a JSON array so multiple-choice questions need no
             * separate shape; the form takes a comma-separated list of
             * keys and casts it on the way in and out.
             */
            TextInput::make('correct_answer')
                ->label('Correct option key(s)')
                ->helperText('The option key, e.g. B. For multiple choice, separate with commas: B,D')
                ->required()
                ->formatStateUsing(fn ($state): string => is_array($state) ? implode(',', $state) : (string) $state)
                ->dehydrateStateUsing(fn (?string $state): array => collect(explode(',', $state ?? ''))
                    ->map(fn (string $value): string => trim($value))
                    ->filter()
                    ->values()
                    ->all())
                ->columnSpanFull(),

            Textarea::make('explanation')
                ->rows(2)
                ->helperText('Shown to trainers only.')
                ->columnSpanFull(),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->alignCenter(),

                TextColumn::make('question')
                    ->limit(70)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),

                TextColumn::make('correct_answer')
                    ->label('Answer')
                    ->formatStateUsing(fn (TrainingQuizQuestion $record): string => implode(', ', $record->correct_answer ?? []))
                    ->badge()
                    ->color('success'),

                TextColumn::make('marks')
                    ->alignEnd(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
