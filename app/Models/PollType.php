<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of poll and the answers it offers (Good / Satisfactory / Bad,
 * Yes / No, ...). Only the Admin defines these (Setting → Poll Types);
 * whoever raises a poll picks one and its options become the poll's
 * answer dropdown. A poll keeps its own snapshot of the options, so
 * editing a type never rewrites a running poll.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property list<string> $options
 * @property list<array{option: string|null, reason: string}>|null $reasons
 * @property bool $allow_comment
 * @property bool $is_active
 * @property int $sort_order
 */
class PollType extends Model
{
    /** The Repeater value meaning "this reason applies to every answer". */
    public const ANY_OPTION = '__any__';

    protected $fillable = [
        'name',
        'description',
        'options',
        'reasons',
        'allow_comment',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'reasons' => 'array',
        'allow_comment' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return list<string>
     */
    public function optionList(): array
    {
        return array_values(array_filter(array_map(
            fn ($option): string => trim((string) $option),
            $this->options ?? [],
        ), fn (string $option): bool => $option !== ''));
    }

    /**
     * The Admin-defined reasons, cleaned: each tied to one answer or to any
     * answer (option null).
     *
     * @return list<array{option: string|null, reason: string}>
     */
    public function reasonList(): array
    {
        return self::cleanReasons($this->reasons ?? []);
    }

    public function hasReasons(): bool
    {
        return $this->reasonList() !== [];
    }

    /**
     * @param  iterable<int, mixed>  $reasons
     * @return list<array{option: string|null, reason: string}>
     */
    public static function cleanReasons(iterable $reasons): array
    {
        $clean = [];

        foreach ($reasons as $row) {
            $reason = trim((string) (is_array($row) ? ($row['reason'] ?? '') : $row));
            $option = is_array($row) ? trim((string) ($row['option'] ?? '')) : '';

            if ($reason === '') {
                continue;
            }

            $clean[] = ['option' => $option === '' || $option === self::ANY_OPTION ? null : $option, 'reason' => $reason];
        }

        return $clean;
    }

    /**
     * The reasons offered once $option has been picked: those tied to it
     * plus those for any answer, in the Admin's order.
     *
     * @param  list<array{option: string|null, reason: string}>  $reasons
     * @return list<string>
     */
    public static function reasonsFor(array $reasons, ?string $option): array
    {
        return array_values(array_unique(array_map(
            fn (array $row): string => $row['reason'],
            array_filter($reasons, fn (array $row): bool => $row['option'] === null || ($option !== null && $row['option'] === $option)),
        )));
    }

    /**
     * @return array<int, string> id => "Feedback (Good / Satisfactory / Bad)"
     */
    public static function options(): array
    {
        return static::query()->active()->ordered()->get()
            ->mapWithKeys(fn (self $type): array => [$type->id => $type->name.' ('.implode(' / ', $type->optionList()).')'])
            ->all();
    }
}
