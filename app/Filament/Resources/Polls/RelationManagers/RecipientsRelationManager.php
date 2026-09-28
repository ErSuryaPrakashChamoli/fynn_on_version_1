<?php

namespace App\Filament\Resources\Polls\RelationManagers;

use App\Models\Employee;
use App\Models\PollRecipient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who the poll went to and who has voted. Shown for anonymous polls too:
 * participation is never secret, only the answer is.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Participation';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user.employee'))
            ->defaultSort('voted_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn (PollRecipient $record): ?string => $record->user?->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.employee.designation')
                    ->label('Designation')
                    ->formatStateUsing(fn ($state): string => Employee::designationOptions()[(int) $state] ?? '—')
                    ->placeholder('—'),

                IconColumn::make('voted')
                    ->label('Voted')
                    ->state(fn (PollRecipient $record): bool => $record->hasVoted())
                    ->boolean(),

                TextColumn::make('voted_at')
                    ->label('Voted at')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('Not yet')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('voted_at')
                    ->label('Voted')
                    ->nullable(),
            ]);
    }
}
