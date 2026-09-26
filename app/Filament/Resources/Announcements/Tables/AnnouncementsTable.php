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
use Illuminate\Database\Eloquent\Builder;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'recipients as acknowledged_recipients_count' => fn (Builder $query) => $query->whereNotNull('acknowledged_at'),
            ]))
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

                TextColumn::make('audience')
                    ->label('Sent to')
                    ->state(fn (Announcement $record): string => $record->audienceLabel())
                    ->wrap()
                    ->sortable(),

                TextColumn::make('acknowledged_recipients_count')
                    ->label('Acknowledged')
                    ->state(fn (Announcement $record): string => "{$record->acknowledged_recipients_count} / {$record->recipients_count}")
                    ->badge()
                    ->color(fn (Announcement $record): string => $record->acknowledged_recipients_count >= $record->recipients_count ? 'success' : 'warning')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Require acknowledgement')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Stop asking after')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Until acknowledged')
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

                SelectFilter::make('audience')
                    ->label('Sent to')
                    ->options(Announcement::AUDIENCES),

                TernaryFilter::make('is_active')
                    ->label('Require acknowledgement'),
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
