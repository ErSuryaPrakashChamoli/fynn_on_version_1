<?php

namespace App\Livewire;

use App\Enums\NotificationCategory;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUpReminderService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Puts the user's oldest pending bell notification in front of them as a
 * pop-up, one at a time, until each is answered:
 *
 *  - Close — done, with remarks (a follow-up is logged as completed).
 *  - Skip — dismissed without action.
 *  - Reschedule — back again at the chosen date and time (a follow-up is
 *    moved to that time in its own log).
 *  - Drop — follow-ups only: stop chasing the prospect.
 *
 * Deliberately NOT blocking, unlike the commitment prompts: skipping is
 * always one click away, and "Skip all" plus "Drop all overdue" handle the
 * bulk case. Every action re-reads the notification through the signed-in
 * user's own notifications, so a crafted request cannot touch anyone else's.
 */
class ReminderPopup extends Component
{
    /**
     * Older unread notifications stay in the bell but no longer pop up, so
     * a long-ignored backlog does not greet the user as a wall of pop-ups.
     */
    public const POP_UP_DAYS = 7;

    /** Which answer form is open: close, reschedule or drop. */
    public ?string $mode = null;

    public ?string $remarks = null;

    public ?string $rescheduleAt = null;

    public function openMode(string $mode): void
    {
        if (! in_array($mode, ['close', 'reschedule', 'drop'], true)) {
            return;
        }

        $this->mode = $mode;
        $this->resetErrorBag();
    }

    public function cancelMode(): void
    {
        $this->reset(['mode', 'remarks', 'rescheduleAt']);
        $this->resetErrorBag();
    }

    public function closeReminder(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if (! $notification) {
            return;
        }

        $this->validate(['remarks' => ['required', 'string', 'min:3', 'max:1000']]);

        $followUp = $this->followUpFor($notification);

        if ($followUp) {
            app(FollowUpReminderService::class)->complete($followUp, $this->user(), (string) $this->remarks);
        }

        $this->resolve($notification, 'closed', $this->remarks);
        $this->done('Reminder closed');
    }

    public function skipReminder(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if (! $notification) {
            return;
        }

        $this->resolve($notification, 'skipped');
        $this->done();
    }

    public function rescheduleReminder(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);

        if (! $notification) {
            return;
        }

        $this->validate([
            'rescheduleAt' => ['required', 'date', 'after:now'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'rescheduleAt.after' => 'Pick a date and time in the future.',
        ]);

        $when = Carbon::parse((string) $this->rescheduleAt);
        $followUp = $this->followUpFor($notification);

        if ($followUp) {
            app(FollowUpReminderService::class)->reschedule($followUp, $this->user(), $when, $this->remarks);
            $this->resolve($notification, 'rescheduled', $this->remarks);
        } else {
            $notification->forceFill([
                'remind_at' => $when,
                'resolution_remarks' => $this->remarks,
            ])->save();
        }

        $this->done('Rescheduled for '.$when->format('d M Y, h:i A'));
    }

    public function dropFollowUp(string $notificationId): void
    {
        $notification = $this->findNotification($notificationId);
        $followUp = $notification ? $this->followUpFor($notification) : null;

        if (! $notification || ! $followUp) {
            return;
        }

        $this->validate(['remarks' => ['required', 'string', 'min:3', 'max:1000']]);

        app(FollowUpReminderService::class)->drop($followUp, $this->user(), $this->remarks);

        $this->resolve($notification, 'dropped', $this->remarks);
        $this->done('Follow-up dropped');
    }

    /** Dismisses every pending reminder at once. */
    public function skipAllReminders(): void
    {
        $this->pendingQuery()->update([
            'read_at' => now(),
            'resolution' => 'skipped',
            'resolved_at' => now(),
            'remind_at' => null,
        ]);

        $this->done('All reminders skipped');
    }

    /** Drops every follow-up of this user that is already past its time. */
    public function dropOverdueFollowUps(): void
    {
        $service = app(FollowUpReminderService::class);

        $dropped = $service->dropMany(
            $service->overdueQueryFor($this->user())->get(),
            $this->user(),
            'Dropped in bulk — follow-up time was over',
        );

        $this->done($dropped.' overdue '.str('follow-up')->plural($dropped).' dropped');
    }

    public function render(): View
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return view('livewire.reminder-popup', ['current' => null, 'pendingCount' => 0, 'overdueCount' => 0]);
        }

        $current = $this->pendingQuery()->reorder('created_at')->first();

        return view('livewire.reminder-popup', [
            'current' => $current,
            'category' => $current ? NotificationCategory::tryFrom((string) $current->category) ?? NotificationCategory::General : null,
            'filament' => $current ? Notification::fromDatabase($current) : null,
            'isFollowUp' => $current && $this->followUpFor($current) !== null,
            'pendingCount' => $this->pendingQuery()->count(),
            'overdueCount' => $user->employee_id ? app(FollowUpReminderService::class)->overdueQueryFor($user)->count() : 0,
            'followUpsUrl' => $this->followUpsUrl(),
        ]);
    }

    /**
     * Unread notifications that are due now — a rescheduled one waits for
     * its remind_at, and one older than POP_UP_DAYS is left to the bell.
     *
     * @return Builder<DatabaseNotification>
     */
    private function pendingQuery(): Builder
    {
        /** @var Builder<DatabaseNotification> $query */
        $query = $this->user()->notifications()->getQuery();

        return $query
            ->whereNull('read_at')
            // Announcements have their own blocking prompt (AnnouncementPrompt).
            ->where(fn (Builder $query) => $query->whereNull('category')->orWhere('category', '!=', NotificationCategory::Announcement->value))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('remind_at')->where('created_at', '>=', now()->subDays(self::POP_UP_DAYS)))
                ->orWhere('remind_at', '<=', now()));
    }

    private function findNotification(string $notificationId): ?DatabaseNotification
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        /** @var DatabaseNotification|null */
        return $user->notifications()->whereKey($notificationId)->first();
    }

    /**
     * The prospect's current follow-up, if this notification is about one.
     */
    private function followUpFor(DatabaseNotification $notification): ?FollowUp
    {
        if ($notification->category !== NotificationCategory::FollowUp->value) {
            return null;
        }

        $followUp = FollowUp::find(data_get($notification->data, 'viewData.follow_up_id'));

        return $followUp ? app(FollowUpReminderService::class)->currentFor($followUp) : null;
    }

    private function resolve(DatabaseNotification $notification, string $resolution, ?string $remarks = null): void
    {
        $notification->forceFill([
            'read_at' => $notification->read_at ?? now(),
            'resolution' => $resolution,
            'resolution_remarks' => $remarks,
            'resolved_at' => now(),
            'remind_at' => null,
        ])->save();
    }

    private function done(?string $message = null): void
    {
        $this->cancelMode();

        if ($message) {
            Notification::make()->title($message)->success()->send();
        }

        // Keeps the bell's unread badge in step without waiting for its poll.
        $this->dispatch('databaseNotificationsSent');
    }

    private function user(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function followUpsUrl(): ?string
    {
        try {
            return FollowUpResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
