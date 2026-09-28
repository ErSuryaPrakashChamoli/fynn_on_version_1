<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ticket priority and the Admin-set number of minutes a ticket of that
 * priority must be resolved within (minutes, since 2026-09-26, because a
 * Critical ticket is due within 10 minutes). The deadline is stamped on the
 * ticket when it is raised (or reopened); changing the SLA here affects
 * tickets raised from then on, plus any the Admin re-prioritises.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property int $resolve_within_minutes
 * @property bool $is_active
 * @property int $sort_order
 */
class ComplaintPriority extends Model
{
    /** Filament badge colours the Admin can pick from. */
    public const COLORS = [
        'gray' => 'Grey',
        'info' => 'Blue',
        'success' => 'Green',
        'warning' => 'Amber',
        'danger' => 'Red',
        'primary' => 'Primary',
    ];

    protected $fillable = [
        'name',
        'color',
        'resolve_within_minutes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'resolve_within_minutes' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'priority_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('resolve_within_minutes');
    }

    /** "10 minutes" / "an hour" / "a day" / "2 days" — the SLA as people read it. */
    public function slaLabel(): string
    {
        $minutes = (int) $this->resolve_within_minutes;

        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            return self::spell(intdiv($minutes, 1440), 'day');
        }

        if ($minutes >= 60 && $minutes % 60 === 0) {
            return self::spell(intdiv($minutes, 60), 'hour');
        }

        return self::spell($minutes, 'minute');
    }

    private static function spell(int $count, string $unit): string
    {
        if ($count === 1) {
            return ($unit === 'hour' ? 'an ' : 'a ').$unit;
        }

        return $count.' '.str($unit)->plural($count);
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->ordered()->get()
            ->mapWithKeys(fn (self $priority): array => [$priority->id => $priority->name.' — resolve within '.$priority->slaLabel()])
            ->all();
    }
}
