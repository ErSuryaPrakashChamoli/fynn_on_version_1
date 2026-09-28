<?php

namespace App\Models;

use App\Models\Concerns\IsEmployeeOption;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A level shown as "Position" on the Employee form, stored by id in
 * employees.designation.
 *
 * The system rows (ids = Employee::DESIGNATION_* codes) drive the reporting
 * tree, targets and role sync, so they can be renamed but never deleted.
 * Positions an admin adds sit outside the reporting tree, like Admin: no
 * boss, no LMS target and no hierarchy role.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_system
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    use IsEmployeeOption {
        deleteBlockedReason as employeeOptionDeleteBlockedReason;
        replacementOptions as employeeOptionReplacementOptions;
    }

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public static function employeeColumn(): string
    {
        return 'designation';
    }

    public static function valueColumn(): string
    {
        return 'id';
    }

    /**
     * Only other positions outside the reporting tree: moving somebody into
     * a hierarchy level also needs a boss, which is chosen on their own
     * Employee form.
     *
     * @return array<int, string>
     */
    public function replacementOptions(): array
    {
        return array_filter(
            $this->employeeOptionReplacementOptions(),
            fn (int $designation): bool => Employee::designationRank($designation) === 0,
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function isProtected(): bool
    {
        return $this->is_system;
    }

    public function deleteBlockedReason(): ?string
    {
        if ($this->is_system) {
            return 'it is a built-in position the reporting hierarchy depends on. It can be renamed instead.';
        }

        return $this->employeeOptionDeleteBlockedReason();
    }
}
