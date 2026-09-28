<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\TargetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stored by code in employees.category. The code of the original amount
 * categories is the amount itself ('2500000'), so employees filed before
 * this table existed keep working; the monthly target is read from
 * target_amount, which the admin may change later.
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

    public static function employeeColumn(): string
    {
        return 'category';
    }

    /**
     * The monthly target for a stored category code: the category's
     * target_amount, else the code itself when it is a bare amount, else
     * null (the caller applies its own default).
     */
    public static function targetAmountFor(?string $code): ?float
    {
        if (blank($code)) {
            return null;
        }

        $key = static::class.'.amounts.'.DB::getDefaultConnection();

        if (! app()->bound($key)) {
            app()->instance($key, static::query()->whereNotNull('target_amount')->pluck('target_amount', 'code')->all());
        }

        $amount = app($key)[$code] ?? null;

        if ($amount !== null) {
            return (float) $amount;
        }

        return is_numeric($code) ? (float) $code : null;
    }

    public static function forgetOptions(): void
    {
        app()->forgetInstance(static::optionsCacheKey());
        app()->forgetInstance(static::class.'.amounts.'.DB::getDefaultConnection());
    }
}
