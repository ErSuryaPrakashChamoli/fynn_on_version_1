<?php

namespace App\Filament\Actions;

use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUpReminderService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * "Drop" for follow-up listings: stops chasing a prospect whose follow-up
 * is over. Written through FollowUpReminderService, so the drop lands in
 * the prospect's own follow-up log as a "Dropped" row with no next date
 * and the prospect comes off every calendar and reminder.
 */
class DropFollowUpActions
{
    /**
     * @param  Closure|null  $followUpResolver  Given a row, returns its current FollowUp (defaults to the row itself).
     */
    public static function record(?Closure $followUpResolver = null): Action
    {
        $followUpResolver ??= fn ($record): ?FollowUp => $record instanceof FollowUp ? $record : null;

        return Action::make('dropFollowUp')
            ->label('Drop')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn ($record): bool => filled($followUpResolver($record)?->next_follow_up_date))
            ->modalHeading('Drop this follow-up?')
            ->modalDescription('The prospect comes off every calendar and reminder. The drop is kept in its follow-up log.')
            ->schema([
                Textarea::make('remarks')
                    ->label('Reason')
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000),
            ])
            ->action(function ($record, array $data) use ($followUpResolver): void {
                $followUp = $followUpResolver($record);
                $user = auth()->user();

                if (! $followUp || ! $user instanceof User) {
                    return;
                }

                $service = app(FollowUpReminderService::class);
                $service->drop($service->currentFor($followUp) ?? $followUp, $user, $data['remarks']);

                Notification::make()->title('Follow-up dropped')->success()->send();
            });
    }

    /**
     * @param  Closure|null  $followUpsResolver  Given the selected rows, returns their FollowUps (defaults to the rows).
     */
    public static function bulk(?Closure $followUpsResolver = null): BulkAction
    {
        $followUpsResolver ??= fn (Collection $records): Collection => $records;

        return BulkAction::make('dropFollowUps')
            ->label('Drop follow-ups')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Drop the selected follow-ups?')
            ->modalDescription('Each prospect comes off every calendar and reminder. The drop is kept in its follow-up log.')
            ->schema([
                Textarea::make('remarks')
                    ->label('Reason')
                    ->default('Follow-up time was over')
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) use ($followUpsResolver): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $dropped = app(FollowUpReminderService::class)->dropMany($followUpsResolver($records), $user, $data['remarks']);

                Notification::make()
                    ->title($dropped.' '.str('follow-up')->plural($dropped).' dropped')
                    ->success()
                    ->send();
            });
    }
}
