<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored by code in employees.unit_name.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $code
 */
class Unit extends Model
{
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
