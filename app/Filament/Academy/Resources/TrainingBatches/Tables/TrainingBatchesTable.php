<?php

namespace App\Filament\Academy\Resources\TrainingBatches\Tables;

use App\Models\Training\TrainingBatch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrainingBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('trainer.name')
                    ->label('Trainer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trainees_count')
                    ->label('Trainees')
                    ->counts('trainees')
                    ->alignEnd(),

                TextColumn::make('average_progress')
                    ->label('Avg. Progress')
                    ->state(fn (TrainingBatch $record): string => $record->averageProgress().'%')
                    ->badge()
                    ->color(fn (TrainingBatch $record): string => match (true) {
                        $record->averageProgress() >= 80 => 'success',
                        $record->averageProgress() >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->alignEnd(),

                TextColumn::make('starts_on')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('ends_on')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'running' => 'success',
                        'completed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('trainer')
                    ->relationship('trainer', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('starts_on', 'desc');
    }
}
