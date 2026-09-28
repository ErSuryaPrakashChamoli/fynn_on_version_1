<?php

namespace App\Filament\Resources\Complaints\Tables;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\ComplaintActions;
use App\Models\Complaint;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplaintsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ticket_no')
                    ->label('Ticket')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Complaint $record): string => $record->created_at?->format('d M Y, h:i A') ?? ''),

                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->wrap()
                    ->description(fn (Complaint $record): ?string => $record->reason?->name),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),

                TextColumn::make('priority.name')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (Complaint $record): string => $record->priority?->color ?? 'gray')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ComplaintStatus $state): string => $state->label())
                    ->color(fn (ComplaintStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('raiser.name')
                    ->label('Raised by')
                    ->description(fn (Complaint $record): ?string => $record->beneficiary ? 'for '.$record->beneficiary->name : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label('Handled by')
                    ->placeholder(fn (Complaint $record): string => $record->handler_role ? $record->handler_role.' team' : 'Unassigned')
                    ->sortable(),

                TextColumn::make('due_at')
                    ->label('Resolve by')
                    ->dateTime('d M, h:i A')
                    ->sortable()
                    ->description(fn (Complaint $record): string => $record->slaLabel())
                    ->color(fn (Complaint $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(fn (Complaint $record): ?string => $record->isOverdue() ? 'heroicon-o-exclamation-triangle' : null),

                TextColumn::make('escalation_level')
                    ->label('Escalated')
                    ->formatStateUsing(fn (int $state, Complaint $record): string => $state > 0
                        ? 'L'.$state.' → '.($record->escalatee?->name ?? 'Admin')
                        : '—')
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null)
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d M Y, h:i A')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ComplaintStatus::options())
                    ->multiple(),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name'),

                SelectFilter::make('priority_id')
                    ->label('Priority')
                    ->relationship('priority', 'name'),

                SelectFilter::make('scope')
                    ->label('Show')
                    ->options([
                        'raised_by_me' => 'Raised by me',
                        'assigned_to_me' => 'Assigned to me',
                        'unassigned' => 'In my team\'s queue, unassigned',
                        'escalated_to_me' => 'Escalated to me',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $userId = Filament::auth()->id();

                        return match ($data['value'] ?? null) {
                            'raised_by_me' => $query->where('raised_by', $userId),
                            'assigned_to_me' => $query->where('assigned_to', $userId),
                            'unassigned' => $query->whereNull('assigned_to')->unresolved(),
                            'escalated_to_me' => $query->where('escalated_to', $userId),
                            default => $query,
                        };
                    }),

                Filter::make('overdue')
                    ->label('Overdue only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Filter::make('unresolved')
                    ->label('Unresolved only')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->unresolved()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    ...ComplaintActions::quick(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No tickets here')
            ->emptyStateDescription('Raise a ticket for anything about Fynn-On, IT, your workspace, an asset, your computer, or your team.');
    }
}
