<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\Announcement;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Flashes an Announcement to everyone who can use the LMS: one bell
 * notification per user, tagged with the announcement's id so the floating
 * banner (AnnouncementBanner) can show it until that user dismisses it.
 */
class AnnouncementService
{
    /**
     * Sends the announcement to every recipient and records how many got it.
     */
    public function publish(Announcement $announcement): int
    {
        $sent = 0;

        $this->recipientsQuery()->chunkById(500, function ($users) use ($announcement, &$sent): void {
            $this->notificationFor($announcement)->sendToDatabase($users);

            $sent += $users->count();
        });

        $announcement->forceFill(['recipients_count' => $sent])->save();

        return $sent;
    }

    /**
     * Admin-panel users whose login still works: switched on, not exited,
     * and not an external portal account (see User::canAccessPanel()).
     *
     * @return Builder<User>
     */
    public function recipientsQuery(): Builder
    {
        return User::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->whereDoesntHave('portalAccount')
            ->whereDoesntHave('employee', fn (Builder $query) => $query->where('exit_status', 'yes'));
    }

    private function notificationFor(Announcement $announcement): Notification
    {
        return Notification::make()
            ->title($announcement->title)
            ->body(Str::limit($announcement->message, 300))
            ->icon(NotificationCategory::Announcement->icon())
            ->status($announcement->level)
            ->viewData([
                ...NotificationCategory::Announcement->viewData(),
                'announcement_id' => $announcement->getKey(),
            ]);
    }
}
