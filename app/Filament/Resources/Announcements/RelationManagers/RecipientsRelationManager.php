<?php

namespace App\Filament\Resources\Announcements\RelationManagers;

use App\Models\AnnouncementRecipient;
use App\Models\Employee;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who an announcement went to and who has acknowledged it. Read-only:
 * recipients are fixed when it is sent (AnnouncementService::publish()).
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Acknowledgements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user.employee'))
            ->defaultSort('acknowledged_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn (AnnouncementRecipient $record): ?string => $record->user?->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.employee.designation')
                    ->label('Designation')
                    ->formatStateUsing(fn ($state): string => Employee::designationOptions()[(int) $state] ?? '—')
                    ->placeholder('—'),

                IconColumn::make('acknowledged')
                    ->label('Acknowledged')
                    ->state(fn (AnnouncementRecipient $record): bool => $record->acknowledged_at !== null)
                    ->boolean(),

                TextColumn::make('acknowledged_at')
                    ->label('Acknowledged at')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Not yet')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('acknowledged_at')
                    ->label('Acknowledged')
                    ->nullable(),
            ]);
    }
}
