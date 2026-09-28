<?php

namespace App\Filament\Resources\Polls\Pages;

use App\Filament\Resources\Polls\PollResource;
use App\Models\Poll;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPoll extends ViewRecord
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close voting')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Nobody can vote after this. Votes already cast are kept.')
                ->visible(fn (): bool => $this->record instanceof Poll && $this->record->is_active)
                ->action(function (): void {
                    $this->record->update(['is_active' => false]);
                    Notification::make()->title('Voting closed')->success()->send();
                }),

            Action::make('reopen')
                ->label('Reopen voting')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof Poll && ! $this->record->is_active && ! $this->record->isExpired())
                ->action(function (): void {
                    $this->record->update(['is_active' => true]);
                    Notification::make()->title('Voting reopened')->success()->send();
                }),

            EditAction::make(),
        ];
    }
}
