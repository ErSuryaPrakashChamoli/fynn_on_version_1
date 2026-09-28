<?php

namespace App\Filament\Resources\Complaints\RelationManagers;

use App\Models\Complaint;
use App\Models\ComplaintComment;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ticket's thread: comments and system events, oldest first. Read-only
 * here — comments are added with the "Add comment" header action so they
 * go through ComplaintService (notifications, first-response stamp).
 * Internal notes are hidden from anyone who cannot handle the ticket.
 */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Thread';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with('user');
                $user = Filament::auth()->user();

                /** @var Complaint $complaint */
                $complaint = $this->getOwnerRecord();

                if (! $user instanceof User || ! app(ComplaintService::class)->canHandle($complaint, $user)) {
                    $query->visibleToRaiser();
                }

                return $query;
            })
            ->defaultSort('created_at', 'asc')
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Who')
                    ->state(fn (ComplaintComment $record): string => $record->isSystem() && $record->user === null
                        ? 'System'
                        : ($record->user?->name ?? 'System')),

                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->color(fn (ComplaintComment $record): ?string => $record->isSystem() ? 'gray' : null)
                    ->icon(fn (ComplaintComment $record): ?string => $record->isSystem() ? 'heroicon-o-bolt' : null),

                IconColumn::make('is_internal')
                    ->label('Internal')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye-slash')
                    ->falseIcon('')
                    ->tooltip('Hidden from the person who raised the ticket'),
            ]);
    }
}
