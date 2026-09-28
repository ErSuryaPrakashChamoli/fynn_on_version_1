<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to a poll: the option picked, the reason (when the poll asks
 * for one) and an optional comment. For an
 * anonymous poll user_id is NULL on purpose — nothing ties the answer back
 * to the person (participation lives on poll_recipients).
 *
 * @property int $id
 * @property int $poll_id
 * @property int|null $user_id
 * @property string $option
 * @property string|null $reason
 * @property string|null $comment
 * @property-read Poll $poll
 * @property-read User|null $user
 */
class PollVote extends Model
{
    protected $fillable = [
        'poll_id',
        'user_id',
        'option',
        'reason',
        'comment',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voterLabel(): string
    {
        return $this->user?->name ?? 'Anonymous';
    }
}
