<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
