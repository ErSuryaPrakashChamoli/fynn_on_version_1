<?php

namespace App\Filament\Academy\Resources\TrainingQuizzes\Tables;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingQuiz;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrainingQuizzesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === TrainingQuiz::KIND_ASSESSMENT ? 'warning' : 'info')
                    ->formatStateUsing(fn (string $state): string => $state === TrainingQuiz::KIND_ASSESSMENT
                        ? 'Assessment'
                        : 'Quiz')
                    ->sortable(),

                TextColumn::make('quizzable_type')
                    ->label('Attached to')
                    ->formatStateUsing(fn (string $state): string => $state === TrainingCourse::class ? 'Course' : 'Lesson')
                    ->toggleable(),

                TextColumn::make('questions_count')
                    ->label('Questions')
                    ->counts('questions')
                    ->alignEnd(),

                TextColumn::make('attempts_count')
                    ->label('Attempts')
                    ->counts('attempts')
                    ->alignEnd(),

                TextColumn::make('pass_percentage')
                    ->label('Pass %')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options([
                        TrainingQuiz::KIND_QUIZ => 'Lesson quiz',
                        TrainingQuiz::KIND_ASSESSMENT => 'Course assessment',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('title');
    }
}
