<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A message the Admin sends from Setting → Announcements to the whole
 * company, chosen roles or chosen designations. Publishing it (see
 * AnnouncementService) records an AnnouncementRecipient per user and drops a
 * bell notification for later reference. While it is active and unexpired,
 * AnnouncementPrompt blocks the LMS for each recipient until they
 * acknowledge it.
 *
 * @property int $id
 * @property string $title
 * @property string $message
 * @property string $level
 * @property string $audience
 * @property array<int, string>|null $audience_roles
 * @property array<int, int>|null $audience_designations
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

    public const AUDIENCE_COMPANY = 'company';

    public const AUDIENCE_ROLES = 'roles';

    public const AUDIENCE_DESIGNATIONS = 'designations';

    /** @var array<string, string> */
    public const AUDIENCES = [
        self::AUDIENCE_COMPANY => 'Company wide',
        self::AUDIENCE_ROLES => 'Role wise',
        self::AUDIENCE_DESIGNATIONS => 'Designation wise',
    ];

    protected $fillable = [
        'title',
        'message',
        'level',
        'audience',
        'audience_roles',
        'audience_designations',
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
            'audience_roles' => 'array',
            'audience_designations' => 'array',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    /**
     * "Company wide", or the chosen roles / designations, for the listing.
     */
    public function audienceLabel(): string
    {
        return match ($this->audience) {
            self::AUDIENCE_ROLES => 'Roles: '.implode(', ', $this->audience_roles ?? []),
            self::AUDIENCE_DESIGNATIONS => 'Designations: '.collect($this->audience_designations ?? [])
                ->map(fn ($designation): string => Employee::designationOptions()[(int) $designation] ?? (string) $designation)
                ->implode(', '),
            default => self::AUDIENCES[self::AUDIENCE_COMPANY],
        };
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Announcements still live: switched on and not past their expiry, so
     * recipients who have not acknowledged them are still blocked.
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isLive(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
