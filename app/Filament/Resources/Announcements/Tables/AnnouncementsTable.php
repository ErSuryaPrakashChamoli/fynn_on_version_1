<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Models\Announcement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Announcement $record): string => str($record->message)->limit(80)->toString()),

                TextColumn::make('level')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Announcement::LEVELS[$state] ?? $state)
                    ->color(fn (string $state): string => $state)
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Floating')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Stops floating')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('When dismissed')
                    ->sortable(),

                TextColumn::make('recipients_count')
                    ->label('Sent to')
                    ->numeric()
                    ->suffix(' users')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Sent by')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Sent at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label('Type')
                    ->options(Announcement::LEVELS),

                TernaryFilter::make('is_active')
                    ->label('Floating'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
