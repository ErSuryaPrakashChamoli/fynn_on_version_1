<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user a poll was sent to, and when they voted. This is the ONLY
 * record of participation for an anonymous poll (the vote itself carries
 * no user), and it is what stops anyone voting twice. A row with no
 * voted_at on a live mandatory poll blocks the LMS behind PollPrompt.
 *
 * @property int $id
 * @property int $poll_id
 * @property int $user_id
 * @property Carbon|null $voted_at
 * @property-read Poll $poll
 * @property-read User $user
 */
class PollRecipient extends Model
{
    protected $fillable = [
        'poll_id',
        'user_id',
        'voted_at',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows still waiting on the user, for polls that are still open. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('voted_at')
            ->whereHas('poll', fn (Builder $query) => $query->live());
    }

    /** Pending rows on mandatory polls — the ones that block the panel. */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereNull('voted_at')
            ->whereHas('poll', fn (Builder $query) => $query->live()->where('is_mandatory', true));
    }

    public function hasVoted(): bool
    {
        return $this->voted_at !== null;
    }
}
