<?php

namespace App\Models;

use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A vote or feedback request raised from Setting → Voting by the Admin or
 * any supervisor above caller level, sent company wide, to chosen roles or
 * to chosen designations. Publishing (PollService::publish) snapshots the
 * audience into poll_recipients and drops a bell notification; each
 * recipient answers once through PollPrompt (mandatory polls block the LMS
 * until answered) or the My Votes page. Anonymous polls store the vote
 * without the voter, and only that they voted.
 *
 * @property int $id
 * @property string $title
 * @property string $question
 * @property int $poll_type_id
 * @property list<string> $options
 * @property bool $allow_comment
 * @property bool $ask_reason
 * @property list<array{option: string|null, reason: string}>|null $reasons
 * @property bool $is_mandatory
 * @property bool $is_anonymous
 * @property string $audience
 * @property list<string>|null $audience_roles
 * @property list<int>|null $audience_designations
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property int $recipients_count
 * @property int $votes_count
 * @property int|null $created_by
 * @property-read PollType $type
 * @property-read User|null $creator
 * @property-read Collection<int, PollRecipient> $recipients
 * @property-read Collection<int, PollVote> $votes
 */
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use HasFactory;

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
        'question',
        'poll_type_id',
        'options',
        'allow_comment',
        'ask_reason',
        'reasons',
        'is_mandatory',
        'is_anonymous',
        'audience',
        'audience_roles',
        'audience_designations',
        'is_active',
        'expires_at',
        'recipients_count',
        'votes_count',
        'created_by',
    ];

    protected $casts = [
        'options' => 'array',
        'allow_comment' => 'boolean',
        'ask_reason' => 'boolean',
        'reasons' => 'array',
        'is_mandatory' => 'boolean',
        'is_anonymous' => 'boolean',
        'audience_roles' => 'array',
        'audience_designations' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'recipients_count' => 'integer',
        'votes_count' => 'integer',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(PollType::class, 'poll_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PollRecipient::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Polls still open: switched on and not past their expiry.
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

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** "Open" / "Closed" / "Expired" for badges. */
    public function statusLabel(): string
    {
        if (! $this->is_active) {
            return 'Closed';
        }

        return $this->isExpired() ? 'Expired' : 'Open';
    }

    public function statusColor(): string
    {
        return match ($this->statusLabel()) {
            'Open' => 'success',
            'Expired' => 'warning',
            default => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public function optionList(): array
    {
        return array_values(array_filter(array_map(
            fn ($option): string => trim((string) $option),
            $this->options ?? [],
        ), fn (string $option): bool => $option !== ''));
    }

    /**
     * @return array<string, string> option => option, for a Select
     */
    public function optionChoices(): array
    {
        $options = $this->optionList();

        return array_combine($options, $options) ?: [];
    }

    /**
     * The reason dropdown for a picked answer — empty when this poll does
     * not ask for reasons or none apply to that answer.
     *
     * @return list<string>
     */
    public function reasonsFor(?string $option): array
    {
        if (! $this->ask_reason) {
            return [];
        }

        return PollType::reasonsFor(PollType::cleanReasons($this->reasons ?? []), $option);
    }

    /**
     * @return array<string, string> reason => reason, for a Select
     */
    public function reasonChoices(?string $option): array
    {
        $reasons = $this->reasonsFor($option);

        return array_combine($reasons, $reasons) ?: [];
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

    public function participationLabel(): string
    {
        return $this->votes_count.' / '.$this->recipients_count.' voted';
    }
}
