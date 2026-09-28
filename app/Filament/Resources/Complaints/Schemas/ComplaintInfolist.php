<?php

namespace App\Filament\Resources\Complaints\Schemas;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ComplaintInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (Complaint $record): string => $record->ticket_no.' — '.$record->subject)
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (ComplaintStatus $state): string => $state->label())
                                ->color(fn (ComplaintStatus $state): string => $state->color())
                                ->icon(fn (ComplaintStatus $state): string => $state->icon()),

                            TextEntry::make('priority.name')
                                ->label('Priority')
                                ->badge()
                                ->color(fn (Complaint $record): string => $record->priority?->color ?? 'gray'),

                            TextEntry::make('due_at')
                                ->label('Resolve by')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('—')
                                ->helperText(fn (Complaint $record): string => $record->slaLabel())
                                ->color(fn (Complaint $record): ?string => $record->isOverdue() ? 'danger' : null),

                            TextEntry::make('escalation_level')
                                ->label('Escalation')
                                ->formatStateUsing(fn (Complaint $record): string => $record->escalation_level > 0
                                    ? 'Level '.$record->escalation_level.' → '.($record->escalatee?->name ?? 'Admin').' on '.$record->escalated_at?->format('d M, h:i A')
                                    : 'Not escalated')
                                ->color(fn (Complaint $record): ?string => $record->escalation_level > 0 ? 'danger' : null),
                        ]),

                        Grid::make(4)->schema([
                            TextEntry::make('category.name')->label('Category'),
                            TextEntry::make('reason.name')->label('Reason')->placeholder('—'),
                            TextEntry::make('raiser.name')
                                ->label('Raised by')
                                ->state(fn (Complaint $record): string => $record->raisedByLabel())
                                ->helperText(fn (Complaint $record): ?string => $record->raiser?->employee?->emp_id),
                            TextEntry::make('handler')
                                ->label('Handled by')
                                ->state(fn (Complaint $record): string => $record->handlerLabel()),
                        ]),

                        Grid::make(4)->schema([
                            TextEntry::make('created_at')->label('Raised on')->dateTime('d M Y, h:i A'),
                            TextEntry::make('first_response_at')->label('First response')->dateTime('d M Y, h:i A')->placeholder('Not yet'),
                            TextEntry::make('resolved_at')->label('Resolved on')->dateTime('d M Y, h:i A')->placeholder('—'),
                            TextEntry::make('reopened_count')
                                ->label('Reopened')
                                ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Never' : $state.' '.str('time')->plural($state)),
                        ]),
                    ]),

                Section::make('Description')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No description was given.')
                            ->columnSpanFull(),

                        TextEntry::make('attachments')
                            ->label('Attachments')
                            ->visible(fn (Complaint $record): bool => filled($record->attachments))
                            ->html()
                            ->state(fn (Complaint $record): HtmlString => new HtmlString(
                                collect($record->attachments ?? [])
                                    ->map(fn (string $path): string => sprintf(
                                        '<a href="%s" target="_blank" rel="noopener" class="fi-link text-primary-600 underline">%s</a>',
                                        e(Storage::disk(ComplaintForm::ATTACHMENT_DISK)->url($path)),
                                        e(basename($path)),
                                    ))
                                    ->implode('<br>'),
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Resolution')
                    ->columnSpanFull()
                    ->visible(fn (Complaint $record): bool => filled($record->resolution_note))
                    ->schema([
                        TextEntry::make('resolution_note')
                            ->hiddenLabel()
                            ->prose()
                            ->columnSpanFull(),

                        Grid::make(3)->schema([
                            TextEntry::make('resolver.name')->label('Resolved by')->placeholder('—'),
                            TextEntry::make('resolved_at')->label('Resolved on')->dateTime('d M Y, h:i A')->placeholder('—'),
                            TextEntry::make('closed_at')->label('Closed on')->dateTime('d M Y, h:i A')->placeholder('Not closed yet'),
                        ]),
                    ]),
            ]);
    }
}
