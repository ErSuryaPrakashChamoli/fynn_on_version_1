<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveInactivation;
use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stored by code in employees.unit_name. Only switched off once nobody
 * holds it, from a dated inactive_from (see HasEffectiveInactivation).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $code
 * @property Carbon|null $inactive_from
 */
class Unit extends Model
{
    use HasEffectiveInactivation;

    /** @use HasFactory<UnitFactory> */
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
        return 'unit_name';
    }
}
