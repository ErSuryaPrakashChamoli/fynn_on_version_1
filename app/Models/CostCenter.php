<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored by code in employees.cost_center.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $code
 */
class CostCenter extends Model
{
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
