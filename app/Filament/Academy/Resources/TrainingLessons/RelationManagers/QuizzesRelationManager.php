<?php

namespace App\Filament\Academy\Resources\TrainingLessons\RelationManagers;

use App\Filament\Academy\Resources\TrainingQuizzes\TrainingQuizResource;
use App\Models\Training\TrainingQuiz;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuizzesRelationManager extends RelationManager
{
    protected static string $relationship = 'quizzes';

    protected static ?string $title = 'Quizzes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),

            TextInput::make('pass_percentage')
                ->label('Pass %')
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->default(60)
                ->required(),

            TextInput::make('time_limit_minutes')
                ->label('Time limit (minutes)')
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
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn ($query) => $query->where('kind', TrainingQuiz::KIND_QUIZ))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('questions_count')
                    ->label('Questions')
                    ->counts('questions')
                    ->alignEnd(),

                TextColumn::make('pass_percentage')
                    ->label('Pass %')
                    ->alignEnd(),

                TextColumn::make('max_attempts')
                    ->label('Attempts')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['kind'] = TrainingQuiz::KIND_QUIZ;

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('questions')
                    ->label('Questions')
                    ->icon('heroicon-o-queue-list')
                    ->url(fn (TrainingQuiz $record): string => TrainingQuizResource::getUrl('edit', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
