<?php

namespace App\Livewire;

use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\AnnouncementService;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Blocks the LMS behind each live announcement the user has not yet
 * acknowledged (Setting → Announcements), oldest first, one at a time.
 * Like the monthly-target prompt it cannot be closed or clicked past;
 * acknowledging is the only way on. The announcement stays in the bell to
 * be read again. Switching an announcement off or letting it expire stops
 * it blocking anyone who has not acknowledged it yet.
 */
class AnnouncementPrompt extends Component
{
    public function acknowledgeAnnouncement(int $recipientId): void
    {
        $recipient = $this->pendingQuery()?->whereKey($recipientId)->first();

        if (! $recipient) {
            return;
        }

        app(AnnouncementService::class)->acknowledge($recipient);

        // Keeps the bell's unread badge in step without waiting for its poll.
        $this->dispatch('databaseNotificationsSent');
    }

    public function render(): View
    {
        $query = $this->pendingQuery();

        return view('livewire.announcement-prompt', [
            'current' => $query ? (clone $query)->with('announcement.creator')->oldest('id')->first() : null,
            'pendingCount' => $query ? (clone $query)->count() : 0,
        ]);
    }

    /**
     * The signed-in user's own unacknowledged, still-live announcements —
     * every action goes through this, so a crafted request cannot
     * acknowledge on someone else's behalf.
     *
     * @return Builder<AnnouncementRecipient>|null
     */
    private function pendingQuery(): ?Builder
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return AnnouncementRecipient::query()
            ->where('user_id', $user->getKey())
            ->pending();
    }
}
