<?php

namespace App\Models;

use Database\Factories\TargetCategoryAmountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The monthly target a Target Category carries from effective_from (always
 * the 1st of a month) until the next row of the same category. Written by
 * TargetCategory whenever its amount is set; read through
 * TargetCategory::targetAmountFor().
 *
 * effective_from is kept as a plain "Y-m-d" string on purpose: the `date`
 * cast writes "Y-m-d H:i:s", which breaks string comparison on SQLite.
 *
 * @property int $id
 * @property int $target_category_id
 * @property string $effective_from
 * @property int|null $amount
 */
class TargetCategoryAmount extends Model
{
    /** @use HasFactory<TargetCategoryAmountFactory> */
    use HasFactory;

    /**
     * The effective_from of an amount that has always applied.
     */
    public const FROM_THE_START = '2000-01-01';

    protected $fillable = [
        'target_category_id',
        'effective_from',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TargetCategory, $this>
     */
    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(TargetCategory::class);
    }
}
