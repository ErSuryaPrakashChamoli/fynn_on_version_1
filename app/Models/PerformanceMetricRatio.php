<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $numerator_key
 * @property string $denominator_key
 * @property string $format
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $created_by
 */
class PerformanceMetricRatio extends Model
{
    public const FORMAT_PERCENTAGE = 'percentage';

    public const FORMAT_DECIMAL = 'decimal';

    protected $fillable = [
        'name',
        'numerator_key',
        'denominator_key',
        'format',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public static function formatOptions(): array
    {
        return [
            self::FORMAT_PERCENTAGE => 'Percentage (%)',
            self::FORMAT_DECIMAL => 'Decimal',
        ];
    }
}
