<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use App\Support\ReportingSnapshot;
use Carbon\CarbonInterface;
use Database\Factories\TargetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Stored by code in employees.category. The code of the original amount
 * categories is the amount itself ('2500000'), so employees filed before
 * this table existed keep working.
 *
 * The monthly target is dated (user decision 2026-11-24): every amount set
 * is written to target_category_amounts from a month (the current one
 * unless amountEffectiveFrom says otherwise), and a month always reads the
 * amount in force in it, so changing Silver from ₹25L to ₹28L in December
 * never restates October. target_amount keeps the last amount entered, for
 * the form; readers go through targetAmountFor().
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $code
 * @property int|null $target_amount
 */
class TargetCategory extends Model
{
    /** @use HasFactory<TargetCategoryFactory> */
    use HasFactory;

    use IsEmployeeOption;

    /**
     * An amount at or below this is a placeholder for "no target" (the
     * form's minimum is ₹1, which is how NIL was entered), never a target.
     */
    public const NO_TARGET_MAX = 1;

    /**
     * The first month (Y-m-d, the 1st) a changed amount applies from. Set it
     * before saving; left null, a change applies from the current month.
     */
    public ?string $amountEffectiveFrom = null;

    protected $fillable = [
        'name',
        'is_active',
        'target_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'target_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (TargetCategory $category): void {
            $category->recordAmount(TargetCategoryAmount::FROM_THE_START);
        });

        static::updated(function (TargetCategory $category): void {
            if ($category->wasChanged('target_amount') || $category->amountEffectiveFrom !== null) {
                $category->recordAmount($category->amountEffectiveFrom ?? now()->startOfMonth()->toDateString());
            }

            $category->amountEffectiveFrom = null;
        });
    }

    public static function employeeColumn(): string
    {
        return 'category';
    }

    /**
     * @return HasMany<TargetCategoryAmount, $this>
     */
    public function amounts(): HasMany
    {
        return $this->hasMany(TargetCategoryAmount::class);
    }

    /**
     * The monthly target for a stored category code in the month $month
     * falls in (this month when null): the amount dated in force then, else
     * — for a category with no dated amount at all — target_amount, else
     * the code itself when it is a bare amount, else null (no target).
     */
    public static function targetAmountFor(?string $code, ?CarbonInterface $month = null): ?float
    {
        if (blank($code)) {
            return null;
        }

        $amounts = self::datedAmounts();

        if (isset($amounts['dated'][$code])) {
            $day = Carbon::parse(($month ?? now())->toDateString())->startOfMonth()->toDateString();
            $amount = $amounts['dated'][$code][0]['amount'];

            foreach ($amounts['dated'][$code] as $row) {
                if ($row['from'] > $day) {
                    break;
                }

                $amount = $row['amount'];
            }

            return $amount === null ? null : (float) $amount;
        }

        if (array_key_exists($code, $amounts['undated'])) {
            return $amounts['undated'][$code] === null ? null : (float) $amounts['undated'][$code];
        }

        return is_numeric($code) ? (float) $code : null;
    }

    /**
     * Whether $amount is a real monthly target (not empty, not the ₹1
     * placeholder).
     */
    public static function isTargetAmount(?float $amount): bool
    {
        return $amount !== null && $amount > self::NO_TARGET_MAX;
    }

    /**
     * The categories a caller may hold, code => name: active ones carrying a
     * real monthly target, plus $current when it still carries one though
     * no longer active. One without an amount (Alpha, Beta, Delta, NIL) is
     * never offered to a caller (user decision 2026-11-24).
     *
     * @return array<int|string, string>
     */
    public static function callerOptionsIncluding(?string $current): array
    {
        $hasTarget = fn (int|string $code): bool => self::isTargetAmount(self::targetAmountFor((string) $code));

        $options = array_filter(static::activeOptions(), $hasTarget, ARRAY_FILTER_USE_KEY);

        if (filled($current) && ! array_key_exists($current, $options) && $hasTarget($current)) {
            $options[$current] = static::labelFor($current) ?? $current;
        }

        return $options;
    }

    /**
     * The dated amounts of this category, oldest first.
     *
     * @return list<array{from: string, amount: ?int}>
     */
    public function amountHistory(): array
    {
        return self::datedAmounts()['dated'][$this->code] ?? [];
    }

    public static function forgetOptions(): void
    {
        app()->forgetInstance(static::optionsCacheKey());
    }

    /**
     * Write the current target_amount as the amount from $from (the 1st of
     * a month), replacing an amount already dated that month.
     */
    private function recordAmount(string $from): void
    {
        $from = Carbon::parse($from)->startOfMonth()->toDateString();

        $existing = $this->amounts()->where('effective_from', $from)->first();

        if ($existing !== null) {
            $existing->update(['amount' => $this->target_amount]);

            return;
        }

        $this->amounts()->create([
            'effective_from' => $from,
            'amount' => $this->target_amount,
        ]);
    }

    /**
     * Every category's dated amounts by code, plus target_amount for those
     * with none — read once and shared like the reporting tree
     * (ReportingSnapshot retires it on any write to either table).
     *
     * @return array{dated: array<string, list<array{from: string, amount: ?int}>>, undated: array<string, ?int>}
     */
    private static function datedAmounts(): array
    {
        return ReportingSnapshot::remember('target-category-amounts', function (): array {
            $codes = static::query()->toBase()->get(['id', 'code', 'target_amount']);
            $dated = [];

            $rows = TargetCategoryAmount::query()
                ->toBase()
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get(['target_category_id', 'effective_from', 'amount'])
                ->groupBy('target_category_id');

            $undated = [];

            foreach ($codes as $category) {
                $categoryRows = $rows->get($category->id);

                if ($categoryRows === null || $categoryRows->isEmpty()) {
                    $undated[(string) $category->code] = $category->target_amount === null ? null : (int) $category->target_amount;

                    continue;
                }

                $dated[(string) $category->code] = $categoryRows
                    ->map(fn (object $row): array => [
                        'from' => substr((string) $row->effective_from, 0, 10),
                        'amount' => $row->amount === null ? null : (int) $row->amount,
                    ])
                    ->values()
                    ->all();
            }

            return ['dated' => $dated, 'undated' => $undated];
        });
    }
}
