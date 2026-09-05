<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The expected OTP count a TL/Manager/Admin set for one caller on one
 * day. Tracked separately from that caller's daily commitment — a caller
 * can have both. The actual OTP count is never stored here; it is read
 * live from `customers` by DailyCommitmentService.
 */
class DailyCallerOtp extends Model
{
    protected $fillable = [
        'employee_id',
        'date',
        'expected_otp',
        'set_by',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_otp' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForDate(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('date', $date);
    }
}
