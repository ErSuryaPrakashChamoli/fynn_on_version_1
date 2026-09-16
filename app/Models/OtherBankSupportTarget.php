<?php

namespace App\Models;

use Database\Factories\OtherBankSupportTargetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly target for one Other Bank Support user. Never read by the LMS
 * target engine (AchievementCalculatorService) or the Daily Commitment module.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $month
 * @property float $target_amount
 */
class OtherBankSupportTarget extends Model
{
    /** @use HasFactory<OtherBankSupportTargetFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'month',
        'target_amount',
    ];

    protected $casts = [
        'month' => 'date',
        'target_amount' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The `date` cast writes "Y-m-d H:i:s", so a bare "Y-m-d" equality misses
     * on SQLite — always match the month with whereDate.
     */
    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('month', $month->copy()->startOfMonth()->toDateString());
    }
}
