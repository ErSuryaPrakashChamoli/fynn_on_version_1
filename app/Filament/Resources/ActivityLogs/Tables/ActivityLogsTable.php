<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Support\SelectedMonth;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //

                TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('User')
                    ->placeholder('System')
                    ->searchable(),

                // BadgeColumn::make('event')
                // ->colors([
                // 'success' => 'created',
                // 'warning' => 'updated',
                // 'danger' => 'deleted',
                // ]),

                TextColumn::make('subject_type')
                    ->label('Module')
                    ->formatStateUsing(fn ($state) => class_basename($state)),

                TextColumn::make('subject_id')
                    ->label('Record ID'),

                TextColumn::make('description'),
            ])
            ->filters([
                //
            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('created_at', SelectedMonth::range())
            )
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
