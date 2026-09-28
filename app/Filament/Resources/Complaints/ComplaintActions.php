<?php

namespace App\Filament\Resources\Complaints;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Models\Complaint;
use App\Models\ComplaintPriority;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * The ticket lifecycle buttons, shared by the listing's row actions and
 * the ticket page's header. Every button re-checks its authority inside
 * ComplaintService, so a hidden button re-posted by hand still fails.
 */
class ComplaintActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::takeUp(),
            self::assign(),
            self::resume(),
            self::hold(),
            self::resolve(),
            self::close(),
            self::reopen(),
            self::reprioritise(),
            self::comment(),
        ];
    }

    /**
     * The few that fit on a listing row.
     *
     * @return array<int, Action>
     */
    public static function quick(): array
    {
        return [
            self::takeUp(),
            self::resolve(),
            self::close(),
            self::reopen(),
        ];
    }

    public static function takeUp(): Action
    {
        return Action::make('takeUp')
            ->label('Take up')
            ->icon('heroicon-o-hand-raised')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Take up this ticket')
            ->modalDescription('It is assigned to you and moves to In Progress.')
            ->visible(fn (Complaint $record): bool => self::canHandle($record)
                && $record->isUnresolved()
                && ! $record->isAssignedTo(self::user()))
            ->action(fn (Complaint $record, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->takeUp($record, self::user()),
                'Ticket taken up',
                $record,
                $livewire,
            ));
    }

    public static function assign(): Action
    {
        return Action::make('assign')
            ->label('Assign')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->modalHeading('Assign this ticket')
            ->schema([
                Select::make('assigned_to')
                    ->label('Assign to')
                    ->options(fn (Complaint $record): array => app(ComplaintService::class)->assignableUsersFor($record, self::user())->all())
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (Complaint $record): bool => self::canHandle($record) && $record->isUnresolved())
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->assign($record, self::user(), User::query()->findOrFail((int) $data['assigned_to'])),
                'Ticket assigned',
                $record,
                $livewire,
            ));
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon('heroicon-o-play-circle')
            ->color('primary')
            ->visible(fn (Complaint $record): bool => self::canHandle($record) && $record->status === ComplaintStatus::OnHold)
            ->action(fn (Complaint $record, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->resume($record, self::user()),
                'Ticket resumed',
                $record,
                $livewire,
            ));
    }

    public static function hold(): Action
    {
        return Action::make('hold')
            ->label('Put on hold')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->modalHeading('Put this ticket on hold')
            ->modalDescription('The deadline keeps running — on hold never pauses the SLA.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is it on hold?')
                    ->rows(3)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (Complaint $record): bool => self::canHandle($record)
                && in_array($record->status, [ComplaintStatus::Open, ComplaintStatus::InProgress], true))
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->hold($record, self::user(), $data['reason']),
                'Ticket put on hold',
                $record,
                $livewire,
            ));
    }

    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->label('Resolve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Resolve this ticket')
            ->modalDescription('The person who raised it is told, and can close it or send it back.')
            ->schema([
                Textarea::make('resolution_note')
                    ->label('What was done?')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (Complaint $record): bool => self::canHandle($record) && $record->isUnresolved())
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->resolve($record, self::user(), $data['resolution_note']),
                'Ticket resolved',
                $record,
                $livewire,
            ));
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Close')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->modalHeading('Close this ticket')
            ->modalDescription(fn (Complaint $record): string => $record->status === ComplaintStatus::Resolved
                ? 'Confirms the resolution settled it.'
                : 'Closes the ticket without a resolution note from the handler.')
            ->schema([
                Textarea::make('note')
                    ->label('Closing note (optional)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->visible(fn (Complaint $record): bool => $record->status !== ComplaintStatus::Closed
                && (self::user()->hasRole('Admin') || ($record->isOwnedBy(self::user()) && $record->status === ComplaintStatus::Resolved)))
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->close($record, self::user(), $data['note'] ?? null),
                'Ticket closed',
                $record,
                $livewire,
            ));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->modalHeading('Reopen this ticket')
            ->modalDescription('It goes back to the handlers with a fresh deadline from now.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is it not resolved?')
                    ->rows(3)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (Complaint $record): bool => ! $record->isUnresolved()
                && ($record->isOwnedBy(self::user()) || self::user()->hasRole('Admin')))
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->reopen($record, self::user(), $data['reason']),
                'Ticket reopened',
                $record,
                $livewire,
            ));
    }

    public static function reprioritise(): Action
    {
        return Action::make('reprioritise')
            ->label('Change priority')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading('Change the priority')
            ->modalDescription('The deadline is recomputed from when the ticket was raised (or last reopened).')
            ->schema([
                Select::make('priority_id')
                    ->label('Priority')
                    ->options(fn (): array => ComplaintPriority::options())
                    ->default(fn (Complaint $record): int => $record->priority_id)
                    ->required(),
            ])
            ->visible(fn (Complaint $record): bool => self::user()->hasRole('Admin') && $record->isUnresolved())
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->reprioritise($record, self::user(), ComplaintPriority::query()->findOrFail((int) $data['priority_id'])),
                'Priority changed',
                $record,
                $livewire,
            ));
    }

    public static function comment(): Action
    {
        return Action::make('comment')
            ->label('Add comment')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('info')
            ->modalHeading('Add a comment')
            ->schema([
                Textarea::make('body')
                    ->label('Comment')
                    ->rows(4)
                    ->required()
                    ->maxLength(3000),

                Toggle::make('is_internal')
                    ->label('Internal note (hidden from the person who raised it)')
                    ->visible(fn (Complaint $record): bool => self::canHandle($record) && ! $record->isOwnedBy(self::user())),
            ])
            ->visible(fn (Complaint $record): bool => app(ComplaintService::class)->canView($record, self::user()))
            ->action(fn (Complaint $record, array $data, Component $livewire) => self::run(
                fn () => app(ComplaintService::class)->comment($record, self::user(), $data['body'], (bool) ($data['is_internal'] ?? false)),
                'Comment added',
                $record,
                $livewire,
            ));
    }

    protected static function canHandle(Complaint $record): bool
    {
        return app(ComplaintService::class)->canHandle($record, self::user());
    }

    protected static function user(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Sign in to work on tickets.');
        }

        return $user;
    }

    /**
     * Runs a service call, turns its refusals into a toast, and reloads
     * the ticket page so the infolist and the thread show the change.
     */
    protected static function run(Closure $callback, string $success, Complaint $record, Component $livewire): void
    {
        try {
            $callback();
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();

        if ($livewire instanceof ViewComplaint) {
            $livewire->redirect(ComplaintResource::getUrl('view', ['record' => $record]), navigate: true);
        }
    }
}
