<?php

namespace App\Filament\Academy\Resources\TrainingAttendances\Tables;

use App\Models\Training\TrainingBatch;
use App\Support\Portal\PortalContext;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainingAttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['batch', 'trainee']))
            ->columns([
                TextColumn::make('attendance_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('batch.name')
                    ->label('Batch')
                    ->searchable()
                    ->limit(28),

                TextColumn::make('trainee.name')
                    ->label('Trainee')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'present' => 'success',
                        'late' => 'warning',
                        'absent' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),

                TextColumn::make('remarks')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('training_batch_id')
                    ->label('Batch')
                    ->options(fn (): array => TrainingBatch::query()
                        ->where('tenant_id', app(PortalContext::class)->tenantId())
                        ->orderByDesc('starts_on')
                        ->pluck('name', 'id')
                        ->all()),

                SelectFilter::make('status')
                    ->options([
                        'present' => 'Present',
                        'absent' => 'Absent',
                        'late' => 'Late',
                        'excused' => 'Excused',
                    ]),

                Filter::make('today')
                    ->label('Today only')
                    ->query(fn (Builder $query) => $query->whereDate('attendance_date', today())),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('attendance_date', 'desc');
    }
}
