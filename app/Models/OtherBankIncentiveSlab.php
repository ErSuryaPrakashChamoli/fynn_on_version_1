<?php

namespace App\Models;

use Database\Factories\OtherBankIncentiveSlabFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One rung of the Other Bank Support incentive ladder. Reaching
 * min_achievement (other-bank count achievement, ₹) pays either a fixed
 * amount or a percentage of that achievement.
 *
 * @property int $id
 * @property Carbon $effective_month
 * @property float $min_achievement
 * @property string $payout_type
 * @property float $payout_value
 */
class OtherBankIncentiveSlab extends Model
{
    /** @use HasFactory<OtherBankIncentiveSlabFactory> */
    use HasFactory;

    public const PAYOUT_FIXED = 'fixed';

    public const PAYOUT_PERCENTAGE = 'percentage';

    protected $fillable = [
        'effective_month',
        'min_achievement',
        'payout_type',
        'payout_value',
    ];

    protected $casts = [
        'effective_month' => 'date',
        'min_achievement' => 'float',
        'payout_value' => 'float',
    ];

    /**
     * @return array<string, string>
     */
    public static function payoutTypeOptions(): array
    {
        return [
            self::PAYOUT_FIXED => 'Fixed amount (₹)',
            self::PAYOUT_PERCENTAGE => '% of achievement',
        ];
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('effective_month', $month->copy()->startOfMonth()->toDateString());
    }

    /** The incentive this slab pays for a given achievement. */
    public function payoutFor(float $achievement): float
    {
        return $this->payout_type === self::PAYOUT_PERCENTAGE
            ? round($achievement * $this->payout_value / 100, 2)
            : $this->payout_value;
    }

    public function payoutLabel(): string
    {
        return $this->payout_type === self::PAYOUT_PERCENTAGE
            ? rtrim(rtrim(number_format($this->payout_value, 4), '0'), '.').'% of achievement'
            : indianAmount($this->payout_value);
    }
}
