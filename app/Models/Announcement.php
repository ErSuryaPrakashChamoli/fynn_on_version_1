<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message the Admin flashes to every active user (Setting → Announcements).
 * Publishing it drops a bell notification on each user (see
 * AnnouncementService); while it is active and unexpired it also floats on
 * screen (AnnouncementBanner) until that user dismisses it.
 *
 * @property int $id
 * @property string $title
 * @property string $message
 * @property string $level
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property int $recipients_count
 * @property int|null $created_by
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const LEVELS = [
        'info' => 'Information',
        'success' => 'Good news',
        'warning' => 'Important',
        'danger' => 'Urgent',
    ];

    protected $fillable = [
        'title',
        'message',
        'level',
        'is_active',
        'expires_at',
        'recipients_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Announcements that should still float on screen.
     */
    public function scopeFloating(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isFloating(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
