<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveInactivation;
use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stored by code in employees.cost_center. Only switched off once nobody
 * holds it, from a dated inactive_from (see HasEffectiveInactivation).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $code
 * @property Carbon|null $inactive_from
 */
class CostCenter extends Model
{
    use HasEffectiveInactivation;

    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    use IsEmployeeOption;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function employeeColumn(): string
    {
        return 'cost_center';
    }
}
