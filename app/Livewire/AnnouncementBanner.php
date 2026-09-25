<?php

namespace App\Livewire;

use App\Enums\NotificationCategory;
use App\Models\Announcement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Floats every announcement the Admin has flashed (Setting → Announcements)
 * over the page until the user dismisses it. Driven by the user's own
 * unread announcement notifications, so dismissing here also clears it
 * from the bell's unread badge; an announcement that is switched off or has
 * expired stops floating but stays in the bell.
 */
class AnnouncementBanner extends Component
{
    public function dismissAnnouncement(string $notificationId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $user->unreadNotifications()
            ->whereKey($notificationId)
            ->where('category', NotificationCategory::Announcement->value)
            ->update(['read_at' => now()]);

        // Keeps the bell's unread badge in step without waiting for its poll.
        $this->dispatch('databaseNotificationsSent');
    }

    public function render(): View
    {
        return view('livewire.announcement-banner', [
            'items' => $this->floatingItems(),
        ]);
    }

    /**
     * @return Collection<int, array{notification: DatabaseNotification, announcement: Announcement}>
     */
    private function floatingItems(): Collection
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        $notifications = $user->unreadNotifications()
            ->where('category', NotificationCategory::Announcement->value)
            ->oldest()
            ->get();

        $announcements = Announcement::query()
            ->floating()
            ->whereKey($notifications->map(fn (DatabaseNotification $notification) => data_get($notification->data, 'viewData.announcement_id'))->filter())
            ->get()
            ->keyBy('id');

        return $notifications
            ->map(fn (DatabaseNotification $notification): ?array => ($announcement = $announcements->get(data_get($notification->data, 'viewData.announcement_id')))
                ? ['notification' => $notification, 'announcement' => $announcement]
                : null)
            ->filter()
            ->values();
    }
}
