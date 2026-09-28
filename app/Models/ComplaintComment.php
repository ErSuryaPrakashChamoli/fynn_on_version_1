<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a ticket's thread: a comment somebody wrote, or a system
 * event (raised, assigned, escalated, resolved, ...) written by the
 * service so the ticket carries its own history.
 *
 * Internal comments are handler notes the person who raised the ticket
 * never sees.
 *
 * @property int $id
 * @property int $complaint_id
 * @property int|null $user_id
 * @property string $type
 * @property string $body
 * @property bool $is_internal
 */
class ComplaintComment extends Model
{
    public const TYPE_COMMENT = 'comment';

    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'complaint_id',
        'user_id',
        'type',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function scopeVisibleToRaiser(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
