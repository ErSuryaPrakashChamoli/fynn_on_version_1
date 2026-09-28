<?php

namespace App\Filament\Resources\Polls\RelationManagers;

use App\Models\Poll;
use App\Models\PollVote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every vote cast: who chose what, with their comment. On an anonymous
 * poll the voter column reads "Anonymous" for every row — the vote has no
 * user, so there is nothing to reveal.
 */
class VotesRelationManager extends RelationManager
{
    protected static string $relationship = 'votes';

    protected static ?string $title = 'Votes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Poll $poll */
        $poll = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user.employee'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Voter')
                    ->state(fn (PollVote $record): string => $record->voterLabel())
                    ->description(fn (PollVote $record): ?string => $record->user?->employee?->emp_id)
                    ->searchable(! $poll->is_anonymous),

                TextColumn::make('option')
                    ->label('Answer')
                    ->badge()
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->visible(fn (): bool => (bool) $poll->ask_reason)
                    ->sortable(),

                TextColumn::make('comment')
                    ->label('Comment')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('created_at')
                    ->label('Voted at')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('option')
                    ->label('Answer')
                    ->options($poll->optionChoices()),

                SelectFilter::make('reason')
                    ->label('Reason')
                    ->visible(fn (): bool => (bool) $poll->ask_reason)
                    ->options(fn (): array => PollVote::query()->where('poll_id', $poll->getKey())->whereNotNull('reason')->distinct()->orderBy('reason')->pluck('reason', 'reason')->all()),
            ]);
    }
}
