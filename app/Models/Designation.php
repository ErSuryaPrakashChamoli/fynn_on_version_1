<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A job title shown as "Designation" on the Employee form. Stored by NAME in
 * employees.position (not employees.designation, which is the Position /
 * hierarchy level), so a rename is carried onto every employee holding it.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
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
        return 'position';
    }

    public static function valueColumn(): string
    {
        return 'name';
    }
}
