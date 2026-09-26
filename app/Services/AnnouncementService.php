<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sends an Announcement to its audience (company wide, chosen roles or chosen
 * designations). Each recipient gets an AnnouncementRecipient row, which
 * AnnouncementPrompt uses to block the LMS until they acknowledge it, and a
 * bell notification so it can be read again later.
 */
class AnnouncementService
{
    /**
     * Sends the announcement to every recipient and records how many got it.
     * The sender is marked as having acknowledged their own announcement.
     */
    public function publish(Announcement $announcement): int
    {
        $sent = 0;
        $notification = $this->notificationFor($announcement);

        // notifyNow(), not Filament's sendToDatabase(): that one queues every
        // notification, so nothing reached a bell until a queue worker ran —
        // and /demo has its own worker (demo:queue-work).
        $this->recipientsQuery($announcement)->chunkById(500, function ($users) use ($announcement, $notification, &$sent): void {
            AnnouncementRecipient::query()->insertOrIgnore($users->map(fn (User $user): array => [
                'announcement_id' => $announcement->getKey(),
                'user_id' => $user->getKey(),
                'acknowledged_at' => $user->getKey() === $announcement->created_by ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            foreach ($users as $user) {
                $user->notifyNow($notification->toDatabase());
            }

            $sent += $users->count();
        });

        $announcement->forceFill(['recipients_count' => $sent])->save();

        return $sent;
    }

    /**
     * Records the user's acknowledgement and marks the matching bell entry
     * read. It stays in the bell to be read again.
     */
    public function acknowledge(AnnouncementRecipient $recipient): void
    {
        $recipient->forceFill(['acknowledged_at' => $recipient->acknowledged_at ?? now()])->save();

        $recipient->user?->unreadNotifications()
            ->where('category', NotificationCategory::Announcement->value)
            ->where('data->viewData->announcement_id', $recipient->announcement_id)
            ->update(['read_at' => now()]);
    }

    /**
     * Admin-panel users whose login still works (switched on, not exited,
     * not an external portal account — see User::canAccessPanel()), narrowed
     * to the announcement's audience.
     *
     * @return Builder<User>
     */
    public function recipientsQuery(Announcement $announcement): Builder
    {
        return User::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->whereDoesntHave('portalAccount')
            ->whereDoesntHave('employee', fn (Builder $query) => $query->where('exit_status', 'yes'))
            ->when(
                $announcement->audience === Announcement::AUDIENCE_ROLES,
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $query) => $query->whereIn('name', $announcement->audience_roles ?? [])),
            )
            ->when(
                $announcement->audience === Announcement::AUDIENCE_DESIGNATIONS,
                fn (Builder $query) => $query->whereHas('employee', fn (Builder $query) => $query->whereIn('designation', $announcement->audience_designations ?? [])),
            );
    }

    private function notificationFor(Announcement $announcement): Notification
    {
        return Notification::make()
            ->title($announcement->title)
            ->body($announcement->message)
            ->icon(NotificationCategory::Announcement->icon())
            ->status($announcement->level)
            ->viewData([
                ...NotificationCategory::Announcement->viewData(),
                'announcement_id' => $announcement->getKey(),
            ]);
    }
}
