<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user an Announcement was sent to, and when they acknowledged it.
 * An unacknowledged row for a still-live announcement blocks the LMS
 * behind the AnnouncementPrompt until the user acknowledges it.
 *
 * @property int $id
 * @property int $announcement_id
 * @property int $user_id
 * @property Carbon|null $acknowledged_at
 */
class AnnouncementRecipient extends Model
{
    protected $fillable = [
        'announcement_id',
        'user_id',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows still waiting on the user, for announcements that are still live.
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('acknowledged_at')
            ->whereHas('announcement', fn (Builder $query) => $query->live());
    }
}
