<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * For an Employee Setup list (see IsEmployeeOption) whose inactivation is
 * dated: an option can only be switched off once no employee holds it, and
 * the day it stops being active is kept in `inactive_from`, so anything
 * worked out for an earlier date still treats it as active.
 *
 * Switch an option off through inactivate() — the saving hook is only the
 * backstop that refuses it while employees still hold the option.
 *
 * @property bool $is_active
 * @property Carbon|null $inactive_from
 */
trait HasEffectiveInactivation
{
    public function initializeHasEffectiveInactivation(): void
    {
        $this->mergeCasts(['inactive_from' => 'date']);
    }

    public static function bootHasEffectiveInactivation(): void
    {
        static::saving(function (self $option): void {
            if (! $option->isDirty('is_active')) {
                return;
            }

            if ($option->is_active) {
                $option->inactive_from = null;

                return;
            }

            if ($option->exists && ($reason = $option->inactivationBlockedReason()) !== null) {
                throw new RuntimeException("{$option->name} cannot be made inactive: {$reason}");
            }

            $option->inactive_from ??= today();
        });
    }

    /**
     * Switch the option off from $from (today or earlier).
     */
    public function inactivate(CarbonInterface $from): void
    {
        if ($from->copy()->startOfDay()->isAfter(today())) {
            throw new RuntimeException('The effective date cannot be in the future.');
        }

        $this->forceFill([
            'is_active' => false,
            'inactive_from' => $from->toDateString(),
        ])->save();
    }

    public function activate(): void
    {
        $this->forceFill([
            'is_active' => true,
            'inactive_from' => null,
        ])->save();
    }

    /**
     * Why the option cannot be switched off yet, or null when it can.
     */
    public function inactivationBlockedReason(): ?string
    {
        $count = $this->employeesCount();

        return $count > 0
            ? "it is still assigned to {$count} ".Str::plural('employee', $count).'. Move them to another option first.'
            : null;
    }

    /**
     * Whether the option counted as active on $date. A row created in this
     * request has no is_active loaded (the default lives in the database),
     * and that must read as active.
     */
    public function wasActiveOn(CarbonInterface $date): bool
    {
        if ($this->is_active ?? true) {
            return true;
        }

        return $this->inactive_from !== null
            && $date->copy()->startOfDay()->lt($this->inactive_from);
    }

    /**
     * Options that counted as active on $date.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query->where('is_active', true)
                ->orWhere('inactive_from', '>', $date->toDateString());
        });
    }
}
