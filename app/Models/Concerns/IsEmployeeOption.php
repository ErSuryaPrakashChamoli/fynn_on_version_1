<?php

namespace App\Models\Concerns;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An admin-managed list whose value is stored on the employees table
 * (Designation, Position, Target Category, Cost Center, Unit).
 *
 * The using model declares which employees column holds its value
 * (employeeColumn()) and which of its own columns that value is
 * (valueColumn(): 'code' by default). A 'code' is generated from the name
 * on create and never changes afterwards, so renaming is always safe; a
 * list that stores its name instead has the rename carried onto every
 * employee. An inactive option is no longer offered on the forms, but the
 * employees holding it keep it. An option still held by an employee cannot
 * be deleted until they are moved to another one (moveEmployeesTo()).
 */
trait IsEmployeeOption
{
    abstract public static function employeeColumn(): string;

    public static function valueColumn(): string
    {
        return 'code';
    }

    public static function bootIsEmployeeOption(): void
    {
        static::creating(function (self $option): void {
            if (static::valueColumn() === 'code' && blank($option->code)) {
                $option->code = static::uniqueCodeFor((string) $option->name);
            }
        });

        static::updating(function (self $option): void {
            $valueColumn = static::valueColumn();

            if ($valueColumn !== 'id' && $option->isDirty($valueColumn)) {
                Employee::query()
                    ->where(static::employeeColumn(), $option->getOriginal($valueColumn))
                    ->update([static::employeeColumn() => $option->getAttribute($valueColumn)]);
            }
        });

        static::deleting(function (self $option): void {
            if (! $option->canBeDeleted()) {
                throw new RuntimeException("{$option->name} cannot be deleted: {$option->deleteBlockedReason()}");
            }
        });

        static::saved(fn () => static::forgetOptions());
        static::deleted(fn () => static::forgetOptions());
    }

    /**
     * Every option, active or not, value => name, ordered by name — for
     * labelling stored values. Memoised per request and per connection (the
     * /demo panel reads its own database).
     *
     * @return array<int|string, string>
     */
    public static function options(): array
    {
        return static::memoisedOptions()['all'];
    }

    /**
     * The options a form may offer for a new choice.
     *
     * @return array<int|string, string>
     */
    public static function activeOptions(): array
    {
        return static::memoisedOptions()['active'];
    }

    /**
     * The active options plus $current when it is inactive or no longer on
     * the list, so editing an employee never silently blanks a value on file.
     *
     * @return array<int|string, string>
     */
    public static function optionsIncluding(int|string|null $current): array
    {
        $options = static::activeOptions();

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = static::labelFor($current);
        }

        return $options;
    }

    /**
     * What the employees holding this option may be moved to before it is
     * deleted: every other active option.
     *
     * @return array<int|string, string>
     */
    public function replacementOptions(): array
    {
        $options = static::activeOptions();

        unset($options[$this->getAttribute(static::valueColumn())]);

        return $options;
    }

    /**
     * Give every employee holding this option $replacement instead. Saved
     * one by one so the Employee model events (role sync, history) run.
     */
    public function moveEmployeesTo(int|string $replacement): int
    {
        if (! array_key_exists($replacement, $this->replacementOptions())) {
            throw new RuntimeException("{$replacement} is not an option {$this->name} can be replaced with.");
        }

        return DB::transaction(function () use ($replacement): int {
            $employees = $this->dependentEmployees()->get();

            $employees->each(fn (Employee $employee) => $employee->update([static::employeeColumn() => $replacement]));

            return $employees->count();
        });
    }

    /**
     * @return Builder<Employee>
     */
    public function dependentEmployees(): Builder
    {
        return Employee::query()->where(static::employeeColumn(), $this->getAttribute(static::valueColumn()));
    }

    /**
     * The display name for a stored value, or the raw value when it is not
     * on the list.
     */
    public static function labelFor(int|string|null $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return static::options()[$value] ?? (string) $value;
    }

    public static function forgetOptions(): void
    {
        app()->forgetInstance(static::optionsCacheKey());
    }

    public function employeesCount(): int
    {
        return $this->dependentEmployees()->count();
    }

    /**
     * A built-in option the application depends on: never deleted, whoever
     * holds it.
     */
    public function isProtected(): bool
    {
        return false;
    }

    public function canBeDeleted(): bool
    {
        return $this->deleteBlockedReason() === null;
    }

    public function deleteBlockedReason(): ?string
    {
        $count = $this->employeesCount();

        return $count > 0
            ? "it is assigned to {$count} ".Str::plural('employee', $count).'. Move them to another option first.'
            : null;
    }

    /**
     * @return array{all: array<int|string, string>, active: array<int|string, string>}
     */
    protected static function memoisedOptions(): array
    {
        $key = static::optionsCacheKey();

        if (! app()->bound($key)) {
            $rows = static::query()->orderBy('name')->get(['name', 'is_active', static::valueColumn()]);

            app()->instance($key, [
                'all' => $rows->pluck('name', static::valueColumn())->all(),
                'active' => $rows->where('is_active', true)->pluck('name', static::valueColumn())->all(),
            ]);
        }

        return app($key);
    }

    protected static function optionsCacheKey(): string
    {
        return static::class.'.options.'.DB::getDefaultConnection();
    }

    protected static function uniqueCodeFor(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'option';
        $code = $base;
        $suffix = 2;

        while (static::query()->where('code', $code)->exists()) {
            $code = "{$base}_{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
