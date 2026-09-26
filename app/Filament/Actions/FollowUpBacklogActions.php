<?php

namespace App\Filament\Actions;

use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUpMonitorService;
use App\Services\FollowUpReminderService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Clears a pile of missed follow-ups in one go, from the calendars' day
 * panel and the Follow-up Monitor. Both write through the usual services,
 * so every move lands in each prospect's own follow-up log.
 */
class FollowUpBacklogActions
{
    /**
     * @param  Closure(): Collection<int, FollowUp>  $followUpsResolver
     */
    public static function spread(string $name, Closure $followUpsResolver, ?Closure $after = null): Action
    {
        return Action::make($name)
            ->label('Reschedule & spread')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->size('sm')
            ->modalHeading('Spread missed follow-ups over the coming days')
            ->modalDescription(fn (): string => sprintf(
                '%d follow-up(s) will be moved, oldest first, filling each caller up to %d a day, %d minutes apart. Sundays are skipped.',
                $followUpsResolver()->count(),
                FollowUpMonitorService::DAILY_CAPACITY,
                FollowUpMonitorService::SLOT_MINUTES,
            ))
            ->schema([
                DatePicker::make('start_date')
                    ->label('Start from')
                    ->default(today())
                    ->minDate(today())
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->required(),
                TextInput::make('days')
                    ->label('Spread over (working days)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(5)
                    ->required(),
                TimePicker::make('start_time')
                    ->label('First call at')
                    ->seconds(false)
                    ->default('10:00')
                    ->required(),
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->default('Missed follow-up rescheduled')
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data) use ($followUpsResolver, $after): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $moved = app(FollowUpMonitorService::class)->spread(
                    $followUpsResolver(),
                    $user,
                    Carbon::parse($data['start_date']),
                    (int) $data['days'],
                    Carbon::parse($data['start_time'])->format('H:i'),
                    $data['remarks'],
                );

                Notification::make()
                    ->title($moved.' '.str('follow-up')->plural($moved).' rescheduled')
                    ->success()
                    ->send();

                if ($after) {
                    $after();
                }
            });
    }

    /**
     * @param  Closure(): Collection<int, FollowUp>  $followUpsResolver
     */
    public static function drop(string $name, Closure $followUpsResolver, ?Closure $after = null): Action
    {
        return Action::make($name)
            ->label('Drop all')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->size('sm')
            ->modalHeading('Drop these missed follow-ups?')
            ->modalDescription(fn (): string => sprintf(
                '%d prospect(s) come off every calendar and reminder. The drop is kept in each follow-up log.',
                $followUpsResolver()->count(),
            ))
            ->schema([
                Textarea::make('remarks')
                    ->label('Reason')
                    ->default('Follow-up time was over')
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data) use ($followUpsResolver, $after): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $dropped = app(FollowUpReminderService::class)->dropMany($followUpsResolver(), $user, $data['remarks']);

                Notification::make()
                    ->title($dropped.' '.str('follow-up')->plural($dropped).' dropped')
                    ->success()
                    ->send();

                if ($after) {
                    $after();
                }
            });
    }
}
