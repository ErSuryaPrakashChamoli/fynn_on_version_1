<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class UserLoginSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'session_id',
        'login_at',
        'logout_at',
        'last_seen_at',
        'last_activity_at',
        'screen_time_seconds',
        'ip_address',
        'user_agent',
        'logout_reason',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'screen_time_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Logout reason written when a session is ended by idleness rather
     * than by the user. Matches the value the login-session log already
     * filters and colours on.
     */
    public const REASON_SESSION_TIMEOUT = 'session_timeout';

    /**
     * Logout reason written when the account behind an open session is
     * switched off (deactivated user, or an employee marked as exited).
     */
    public const REASON_ACCOUNT_DEACTIVATED = 'account_deactivated';

    /**
     * Minutes of no interaction before a session is considered idle.
     * 0 disables idle logout.
     */
    public static function idleTimeoutMinutes(): int
    {
        return max(0, (int) config('session.idle_timeout', 15));
    }

    public static function idleTimeoutEnabled(): bool
    {
        return static::idleTimeoutMinutes() > 0;
    }

    /**
     * Open sessions whose last genuine interaction is older than the idle
     * window. Used by the sweeper command and by the log listing.
     */
    public function scopeIdle(Builder $query, ?Carbon $asOf = null): Builder
    {
        $cutoff = ($asOf ?? now())->copy()->subMinutes(static::idleTimeoutMinutes());

        return $query
            ->whereNull('logout_at')
            ->where(function (Builder $inner) use ($cutoff): void {
                $inner
                    ->where('last_activity_at', '<', $cutoff)
                    // A session that somehow never recorded an
                    // interaction falls back to its login time, so it
                    // cannot sit open forever.
                    ->orWhere(function (Builder $noActivity) use ($cutoff): void {
                        $noActivity
                            ->whereNull('last_activity_at')
                            ->where('login_at', '<', $cutoff);
                    });
            });
    }

    /**
     * The last moment this session showed a genuine user interaction.
     */
    public function lastInteractionAt(): ?Carbon
    {
        return $this->last_activity_at ?? $this->login_at;
    }

    public function isIdle(?Carbon $asOf = null): bool
    {
        if (! static::idleTimeoutEnabled()) {
            return false;
        }

        $lastInteraction = $this->lastInteractionAt();

        if ($lastInteraction === null) {
            return false;
        }

        return $lastInteraction->lt(
            ($asOf ?? now())->copy()->subMinutes(static::idleTimeoutMinutes())
        );
    }

    /**
     * Seconds of idleness still allowed before this session is closed.
     */
    public function secondsUntilIdleLogout(?Carbon $asOf = null): ?int
    {
        if (! static::idleTimeoutEnabled()) {
            return null;
        }

        $lastInteraction = $this->lastInteractionAt();

        if ($lastInteraction === null) {
            return null;
        }

        $deadline = $lastInteraction->copy()->addMinutes(static::idleTimeoutMinutes());

        return max(0, (int) ($asOf ?? now())->diffInSeconds($deadline, false));
    }

    /**
     * Close this session because nobody was using it.
     *
     * Idempotent: a session already closed (by a logout, a new login, or
     * an earlier sweep) is left exactly as it was, so the log never
     * rewrites a reason that already happened.
     */
    public function closeAsIdle(?Carbon $at = null): bool
    {
        if ($this->logout_at !== null) {
            return false;
        }

        $this->forceFill([
            'logout_at' => $at ?? now(),
            'logout_reason' => self::REASON_SESSION_TIMEOUT,
        ])->save();

        return true;
    }

    /**
     * Close this session because the account behind it was switched off.
     *
     * Idempotent for the same reason closeAsIdle() is: a session already
     * closed keeps the reason it was closed with.
     */
    public function closeAsDeactivated(?Carbon $at = null): bool
    {
        if ($this->logout_at !== null) {
            return false;
        }

        $this->forceFill([
            'logout_at' => $at ?? now(),
            'logout_reason' => self::REASON_ACCOUNT_DEACTIVATED,
        ])->save();

        return true;
    }

    /**
     * "Currently at the keyboard" — an open session with a recent
     * interaction. The login-session log renders its Activity badge from
     * this; it is derived rather than stored so it can never go stale.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->logout_at === null && ! $this->isIdle();
    }

    /**
     * Return screen time in HH:MM:SS format.
     */
    public function getScreenTimeFormattedAttribute(): string
    {
        $seconds = max(0, (int) $this->screen_time_seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf(
            '%02d:%02d:%02d',
            $hours,
            $minutes,
            $remainingSeconds
        );
    }

    /**
     * Return screen time in human-readable format.
     *
     * Example:
     * 8h 23m
     */
    public function getScreenTimeHumanAttribute(): string
    {
        $seconds = max(0, (int) $this->screen_time_seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
