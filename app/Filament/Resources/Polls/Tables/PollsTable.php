<?php

namespace App\Filament\Resources\Polls\Tables;

use App\Models\Poll;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PollsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Poll')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Poll $record): string => str($record->question)->limit(80)),

                TextColumn::make('type.name')
                    ->label('Type')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Poll $record): string => $record->statusLabel())
                    ->color(fn (Poll $record): string => $record->statusColor()),

                TextColumn::make('audience')
                    ->label('Sent to')
                    ->state(fn (Poll $record): string => $record->audienceLabel())
                    ->wrap()
                    ->limit(60),

                IconColumn::make('is_mandatory')
                    ->label('Mandatory')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_anonymous')
                    ->label('Anonymous')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('votes_count')
                    ->label('Votes')
                    ->state(fn (Poll $record): string => $record->participationLabel())
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Closes')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('Until closed')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Raised by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d M Y, h:i A')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('poll_type_id')
                    ->label('Type')
                    ->relationship('type', 'name'),

                SelectFilter::make('audience')
                    ->options(Poll::AUDIENCES),

                TernaryFilter::make('is_mandatory')
                    ->label('Mandatory'),

                TernaryFilter::make('is_anonymous')
                    ->label('Anonymous'),

                TernaryFilter::make('open')
                    ->label('Open for voting')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->tap(fn (Builder $query) => $query->live()),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                            ->where('is_active', false)
                            ->orWhere('expires_at', '<=', now())),
                    ),
            ])
            ->recordActions([
                ViewAction::make()->label('Results'),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
