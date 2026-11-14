<?php

namespace App\Support\ColumnHeaderFilters;

use App\Enums\ColumnHeaderFilterKind;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The Excel-style filter one listing column offers from its header.
 *
 * Which kind a column gets is worked out from the column itself, so no
 * table has to declare anything:
 *  - a real database column (or a column reached through relationships) →
 *    a checklist of its distinct values, or a from / to range for dates;
 *  - a computed column with a select filter of the same name on the table →
 *    that filter's options, applied through the filter's own query;
 *  - a computed column that is searchable → a "contains" box, applied
 *    through the column's own search query;
 *  - anything else → no header filter.
 *
 * Values travel to the browser as tokens. A value that is shown exactly as
 * it is stored travels as itself ("r:Pending"); one the column formats
 * (masked mobile numbers, amounts, enum labels) travels encrypted ("e:…"),
 * so a header filter never reveals what the listing deliberately hides.
 */
final class ColumnHeaderFilter
{
    /** How many distinct values one checklist lists before asking for a search. */
    public const OPTIONS_LIMIT = 300;

    private const DATE_TYPES = ['date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz'];

    private const TEXT_TYPES = ['char', 'varchar', 'string', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'character varying', 'bpchar'];

    private const UNFILTERABLE_TYPES = ['json', 'jsonb', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea', 'geometry', 'point'];

    /** @var array<string, array<string, string>> */
    private static array $databaseColumns = [];

    private function __construct(
        public readonly Column $column,
        public readonly ColumnHeaderFilterKind $kind,
        private readonly Model $model,
        private readonly ?string $relationshipName = null,
        private readonly ?Model $relatedModel = null,
        private readonly ?string $attribute = null,
        private readonly bool $holdsText = false,
        private readonly ?SelectFilter $selectFilter = null,
    ) {}

    /**
     * The header filter a column offers, or null when it offers none.
     */
    public static function for(Column $column): ?self
    {
        if ($column->isHidden() || $column instanceof ImageColumn) {
            return null;
        }

        $modelClass = $column->getTable()->getModel();

        if (blank($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $model = new $modelClass;

        return self::forDatabaseColumn($column, $model)
            ?? self::forSelectFilter($column, $model)
            ?? self::forSearch($column, $model);
    }

    /**
     * Drops anything in a filter state this column cannot use, so state
     * from the URL or the browser is never trusted as-is.
     *
     * @return array{values?: list<string>, blanks?: bool, contains?: string, from?: string, to?: string}|null
     */
    public function sanitize(mixed $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        return match ($this->kind) {
            ColumnHeaderFilterKind::Values => $this->sanitizeChecklist($state, allowsContains: true),
            ColumnHeaderFilterKind::Options => $this->sanitizeChecklist($state, allowsContains: false),
            ColumnHeaderFilterKind::DateRange => $this->sanitizeDateRange($state),
            ColumnHeaderFilterKind::Text => $this->sanitizeContains($state),
        };
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     */
    public function apply(Builder|Relation $query, mixed $state): void
    {
        $state = $this->sanitize($state);

        if ($state === null) {
            return;
        }

        $query->where(function (Builder $query) use ($state): void {
            match ($this->kind) {
                ColumnHeaderFilterKind::Values => $this->applyValues($query, $state),
                ColumnHeaderFilterKind::DateRange => $this->applyDateRange($query, $state),
                ColumnHeaderFilterKind::Options => $this->applyOptions($query, $state),
                ColumnHeaderFilterKind::Text => $this->applyContains($query, $state['contains']),
            };
        });
    }

    /**
     * What the header popover needs to draw itself: the checklist (with
     * which entries are ticked), the date range, or the "contains" text.
     *
     * @param  Builder<Model>  $query  The listing's rows under every other filter.
     * @return array<string, mixed>
     */
    public function options(Builder $query, ?array $state, ?string $search = null): array
    {
        $search = filled($search = trim((string) $search)) ? mb_substr($search, 0, 255) : null;

        return [
            'available' => true,
            'kind' => $this->kind->value,
            'state' => $state,
            ...match ($this->kind) {
                ColumnHeaderFilterKind::Values => $this->valueOptions($query, $state, $search),
                ColumnHeaderFilterKind::Options => $this->selectFilterOptions($state, $search),
                ColumnHeaderFilterKind::DateRange => ['bounds' => $this->dateBounds($query)],
                ColumnHeaderFilterKind::Text => [],
            },
        ];
    }

    /**
     * The chip shown above the listing while this filter is on, e.g.
     * "Status: Pending, Missed" or "Due: 01 Oct 2026 – 31 Oct 2026".
     */
    public function indicator(mixed $state): ?string
    {
        $state = $this->sanitize($state);

        if ($state === null) {
            return null;
        }

        $label = $this->columnLabel();

        if (isset($state['contains'])) {
            return "{$label} contains “{$state['contains']}”";
        }

        if ($this->kind === ColumnHeaderFilterKind::DateRange) {
            $from = isset($state['from']) ? Carbon::parse($state['from'])->format('d M Y') : null;
            $to = isset($state['to']) ? Carbon::parse($state['to'])->format('d M Y') : null;

            return $label.': '.match (true) {
                $from !== null && $to !== null => $from === $to ? $from : "{$from} – {$to}",
                $from !== null => "from {$from}",
                default => "until {$to}",
            };
        }

        $labels = $this->selectedLabels($state);

        if ($labels === []) {
            return null;
        }

        $shown = array_slice($labels, 0, 3);
        $more = count($labels) - count($shown);

        return $label.': '.implode(', ', $shown).($more > 0 ? " +{$more} more" : '');
    }

    public function columnLabel(): string
    {
        $label = $this->column->getLabel();

        return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
    }

    public static function decodeToken(mixed $token): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        if (str_starts_with($token, 'r:')) {
            return substr($token, 2);
        }

        if (str_starts_with($token, 'e:')) {
            try {
                return Crypt::decryptString(substr($token, 2));
            } catch (DecryptException) {
                return null;
            }
        }

        return null;
    }

    private static function forDatabaseColumn(Column $column, Model $model): ?self
    {
        // A column that works its value out in PHP may show something quite
        // different from the database column it happens to be named after.
        if ($column->getGetStateUsingCallback() !== null) {
            return null;
        }

        $relationshipName = null;
        $relatedModel = null;
        $attribute = $column->getName();

        if ($column->hasRelationship($model)) {
            $relationshipName = $column->getRelationshipName($model);
            $relatedModel = self::relatedModelThrough($model, (string) $relationshipName);
            $attribute = $column->getFullAttributeName($model);

            if ($relatedModel === null) {
                return null;
            }
        }

        // JSON paths ("data.city") are not plain columns.
        if (str_contains($attribute, '.')) {
            return null;
        }

        $type = self::databaseColumns($relatedModel ?? $model)[$attribute] ?? null;

        if ($type === null || in_array($type, self::UNFILTERABLE_TYPES, true)) {
            return null;
        }

        return new self(
            column: $column,
            kind: in_array($type, self::DATE_TYPES, true) ? ColumnHeaderFilterKind::DateRange : ColumnHeaderFilterKind::Values,
            model: $model,
            relationshipName: $relationshipName,
            relatedModel: $relatedModel,
            attribute: $attribute,
            holdsText: in_array($type, self::TEXT_TYPES, true),
        );
    }

    private static function forSelectFilter(Column $column, Model $model): ?self
    {
        $filter = $column->getTable()->getFilter($column->getName());

        if (! $filter instanceof SelectFilter || $filter instanceof TernaryFilter) {
            return null;
        }

        return new self(
            column: $column,
            kind: ColumnHeaderFilterKind::Options,
            model: $model,
            selectFilter: $filter,
        );
    }

    private static function forSearch(Column $column, Model $model): ?self
    {
        if (! $column->isSearchable()) {
            return null;
        }

        return new self(
            column: $column,
            kind: ColumnHeaderFilterKind::Text,
            model: $model,
        );
    }

    /**
     * The model at the end of a dotted relationship chain, or null when the
     * chain cannot be queried with whereHas() (morph-to, or not a relation).
     */
    private static function relatedModelThrough(Model $model, string $relationshipName): ?Model
    {
        $current = $model;

        foreach (explode('.', $relationshipName) as $segment) {
            try {
                $relation = $current->{$segment}();
            } catch (Throwable) {
                return null;
            }

            if (! $relation instanceof Relation || $relation instanceof MorphTo) {
                return null;
            }

            $current = $relation->getRelated();
        }

        return $current;
    }

    /**
     * Column name => lower-case type name, read once per table per process.
     *
     * @return array<string, string>
     */
    private static function databaseColumns(Model $model): array
    {
        $key = $model->getConnectionName().'|'.$model->getTable();

        return self::$databaseColumns[$key] ??= collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))
            ->mapWithKeys(fn (array $column): array => [$column['name'] => strtolower((string) $column['type_name'])])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{values?: list<string>, blanks?: bool, contains?: string}|null
     */
    private function sanitizeChecklist(array $state, bool $allowsContains): ?array
    {
        if ($allowsContains && ($contains = $this->sanitizeContains($state)) !== null) {
            return $contains;
        }

        $values = collect(is_array($state['values'] ?? null) ? $state['values'] : [])
            ->filter(fn (mixed $token): bool => is_string($token)
                && strlen($token) <= 4096
                && (str_starts_with($token, 'r:') || str_starts_with($token, 'e:')))
            ->unique()
            ->take(1000)
            ->values()
            ->all();

        $blanks = $this->kind === ColumnHeaderFilterKind::Values
            && filter_var($state['blanks'] ?? false, FILTER_VALIDATE_BOOL);

        if ($values === [] && ! $blanks) {
            return null;
        }

        return array_filter(['values' => $values, 'blanks' => $blanks]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{from?: string, to?: string}|null
     */
    private function sanitizeDateRange(array $state): ?array
    {
        $date = function (mixed $value): ?string {
            if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
                return null;
            }

            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
        };

        $from = $date($state['from'] ?? null);
        $to = $date($state['to'] ?? null);

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $range = array_filter(['from' => $from, 'to' => $to]);

        return $range === [] ? null : $range;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{contains: string}|null
     */
    private function sanitizeContains(array $state): ?array
    {
        $contains = $state['contains'] ?? null;

        if (! is_string($contains) || blank($contains = trim(mb_substr($contains, 0, 255)))) {
            return null;
        }

        return ['contains' => $contains];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array{values?: list<string>, blanks?: bool, contains?: string}  $state
     */
    private function applyValues(Builder $query, array $state): void
    {
        if (isset($state['contains'])) {
            $contains = $state['contains'];

            $this->whereTarget($query, fn (Builder $query, string $column): Builder => $query->where($column, 'like', "%{$contains}%"));

            return;
        }

        $values = $this->decodeValues($state['values'] ?? []);

        if ($values === [] && ! ($state['blanks'] ?? false)) {
            // Every ticked value was unreadable (e.g. the app key changed):
            // match nothing rather than silently dropping the filter.
            $query->whereRaw('1 = 0');

            return;
        }

        if ($values !== []) {
            $this->whereTarget($query, fn (Builder $query, string $column): Builder => $query->whereIn($column, $values));
        }

        if ($state['blanks'] ?? false) {
            $query->orWhere(fn (Builder $query) => $this->whereBlank($query));
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array{from?: string, to?: string}  $state
     */
    private function applyDateRange(Builder $query, array $state): void
    {
        $this->whereTarget($query, function (Builder $query, string $column) use ($state): Builder {
            if (isset($state['from'])) {
                $query->whereDate($column, '>=', $state['from']);
            }

            if (isset($state['to'])) {
                $query->whereDate($column, '<=', $state['to']);
            }

            return $query;
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array{values?: list<string>}  $state
     */
    private function applyOptions(Builder $query, array $state): void
    {
        $filter = $this->selectFilter;
        $keys = $this->decodeValues($state['values'] ?? []);

        if ($filter === null || $keys === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($filter->isMultiple()) {
            $filter->apply($query, ['values' => $keys]);

            return;
        }

        foreach ($keys as $key) {
            $query->orWhere(fn (Builder $query): Builder => $filter->apply($query, ['value' => $key]));
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyContains(Builder $query, string $search): void
    {
        $isFirst = true;

        $this->column->applySearchConstraint($query, $search, $isFirst);
    }

    /**
     * Applies a constraint to the column the filter reads — on the listed
     * row itself, or inside whereHas() for a relationship column.
     *
     * @param  Builder<Model>  $query
     * @param  Closure(Builder<Model>, string): Builder<Model>  $constraint
     */
    private function whereTarget(Builder $query, Closure $constraint): void
    {
        if ($this->relationshipName === null) {
            $constraint($query, $query->qualifyColumn((string) $this->attribute));

            return;
        }

        $query->whereHas(
            $this->relationshipName,
            fn (Builder $related): Builder => $constraint($related, $related->qualifyColumn((string) $this->attribute)),
        );
    }

    /**
     * Rows whose value is empty — no related record at all counts too.
     *
     * @param  Builder<Model>  $query
     */
    private function whereBlank(Builder $query): void
    {
        $isBlank = function (Builder $query, string $column): Builder {
            return $query->where(function (Builder $query) use ($column): void {
                $query->whereNull($column);

                // Only text can be '' — on MySQL a number column would also
                // match 0 against an empty string.
                if ($this->holdsText) {
                    $query->orWhere($column, '');
                }
            });
        };

        if ($this->relationshipName === null) {
            $isBlank($query, $query->qualifyColumn((string) $this->attribute));

            return;
        }

        $query->where(fn (Builder $query): Builder => $query
            ->whereDoesntHave($this->relationshipName)
            ->orWhereHas(
                $this->relationshipName,
                fn (Builder $related): Builder => $isBlank($related, $related->qualifyColumn((string) $this->attribute)),
            ));
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{options: list<array{label: string, values: list<string>, checked: bool}>, blanks: array{checked: bool}|null, truncated: bool, search: ?string}
     */
    private function valueOptions(Builder $query, ?array $state, ?string $search): array
    {
        $rawValues = $this->distinctValues($query, $search);
        $isTruncated = count($rawValues) > self::OPTIONS_LIMIT;
        $rawValues = array_slice($rawValues, 0, self::OPTIONS_LIMIT);

        $selected = array_flip($this->decodeValues($state['values'] ?? []));
        $contains = isset($state['contains']) ? mb_strtolower($state['contains']) : null;

        $options = [];

        foreach ($rawValues as $raw) {
            $raw = (string) $raw;
            $label = $this->labelFor($raw);

            $options[$label] ??= ['label' => $label, 'values' => [], 'checked' => false];
            $options[$label]['values'][] = $raw === $label ? 'r:'.$raw : 'e:'.Crypt::encryptString($raw);

            if (
                $state === null
                || isset($selected[$raw])
                || ($contains !== null && str_contains(mb_strtolower($raw), $contains))
            ) {
                $options[$label]['checked'] = true;
            }
        }

        $hasBlanks = $search === null && $this->hasBlanks($query);

        return [
            'options' => array_values($options),
            'blanks' => $hasBlanks
                ? ['checked' => $state === null || (bool) ($state['blanks'] ?? false)]
                : null,
            'truncated' => $isTruncated,
            'search' => $search,
        ];
    }

    /**
     * @return array{options: list<array{label: string, values: list<string>, checked: bool}>, blanks: null, truncated: bool, search: ?string}
     */
    private function selectFilterOptions(?array $state, ?string $search): array
    {
        $selected = array_flip($this->decodeValues($state['values'] ?? []));
        $options = [];

        foreach ($this->selectFilterOptionLabels() as $key => $label) {
            if ($search !== null && ! str_contains(mb_strtolower($label), mb_strtolower($search))) {
                continue;
            }

            $options[] = [
                'label' => $label,
                'values' => ['r:'.$key],
                'checked' => $state === null || isset($selected[(string) $key]),
            ];
        }

        return [
            'options' => $options,
            'blanks' => null,
            'truncated' => false,
            'search' => $search,
        ];
    }

    /**
     * The select filter's options flattened out of any option groups.
     *
     * @return array<string, string>
     */
    private function selectFilterOptionLabels(): array
    {
        $labels = [];

        foreach ($this->selectFilter?->getOptions() ?? [] as $key => $label) {
            if (is_array($label)) {
                foreach ($label as $groupedKey => $groupedLabel) {
                    $labels[(string) $groupedKey] = self::stringify($groupedLabel);
                }

                continue;
            }

            $labels[(string) $key] = self::stringify($label);
        }

        return $labels;
    }

    /**
     * The distinct, non-empty values of the column across the given rows,
     * ordered, one more than the limit so a cut-off list can say so.
     *
     * @param  Builder<Model>  $query
     * @return list<mixed>
     */
    private function distinctValues(Builder $query, ?string $search): array
    {
        $narrow = function (QueryBuilder $values, string $column) use ($search): QueryBuilder {
            return $values
                ->whereNotNull($column)
                ->when($this->holdsText, fn (QueryBuilder $values): QueryBuilder => $values->where($column, '<>', ''))
                ->when($search !== null, fn (QueryBuilder $values): QueryBuilder => $values->where($column, 'like', "%{$search}%"))
                ->select("{$column} as header_filter_value")
                ->distinct()
                ->orderBy('header_filter_value')
                ->limit(self::OPTIONS_LIMIT + 1);
        };

        if ($this->relationshipName === null) {
            $values = $query->clone()->toBase()->reorder();

            return $narrow($values, $query->qualifyColumn((string) $this->attribute))
                ->pluck('header_filter_value')
                ->all();
        }

        /** @var Model $related */
        $related = $this->relatedModel;

        // One hop through a belongs-to (owner, customer, lead…): read the
        // related table for the keys the listed rows point at.
        if (! str_contains($this->relationshipName, '.')) {
            $relation = $this->model->{$this->relationshipName}();

            if ($relation instanceof BelongsTo) {
                $keys = $query->clone()->toBase()->reorder()->select($relation->getQualifiedForeignKeyName());

                $values = $related->newQuery()->toBase()->whereIn($relation->getQualifiedOwnerKeyName(), $keys);

                return $narrow($values, $related->qualifyColumn((string) $this->attribute))
                    ->pluck('header_filter_value')
                    ->all();
            }
        }

        // Any other chain: related rows that at least one listed row reaches.
        $alias = 'header_filter_rows';
        $keyName = $related->getKeyName();

        $reached = $query->clone()->reorder()
            ->whereHas($this->relationshipName, fn (Builder $inner): Builder => $inner->whereColumn(
                $inner->qualifyColumn($keyName),
                "{$alias}.{$keyName}",
            ))
            ->toBase()
            ->select(new Expression('1'));

        $values = $related->getConnection()
            ->table("{$related->getTable()} as {$alias}")
            ->whereExists($reached);

        return $narrow($values, "{$alias}.{$this->attribute}")
            ->pluck('header_filter_value')
            ->all();
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function hasBlanks(Builder $query): bool
    {
        return $query->clone()
            ->reorder()
            ->where(fn (Builder $query) => $this->whereBlank($query))
            ->toBase()
            ->exists();
    }

    /**
     * The earliest and latest date the rows hold, for the date inputs.
     *
     * @param  Builder<Model>  $query
     * @return array{min: ?string, max: ?string}
     */
    private function dateBounds(Builder $query): array
    {
        if ($this->relationshipName !== null) {
            return ['min' => null, 'max' => null];
        }

        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn((string) $this->attribute));

        $bounds = $query->clone()->toBase()->reorder()
            ->select(new Expression("min({$column}) as header_filter_min, max({$column}) as header_filter_max"))
            ->first();

        $date = fn (mixed $value): ?string => filled($value) ? Carbon::parse($value)->toDateString() : null;

        return [
            'min' => $date($bounds->header_filter_min ?? null),
            'max' => $date($bounds->header_filter_max ?? null),
        ];
    }

    /**
     * @param  array{values?: list<string>, blanks?: bool}  $state
     * @return list<string>
     */
    private function selectedLabels(array $state): array
    {
        $raw = $this->decodeValues($state['values'] ?? []);

        $labels = $this->kind === ColumnHeaderFilterKind::Options
            ? array_values(array_intersect_key($this->selectFilterOptionLabels(), array_flip($raw)))
            : array_values(array_unique(array_map(fn (string $value): string => $this->labelFor($value), $raw)));

        if ($state['blanks'] ?? false) {
            $labels[] = '(Blanks)';
        }

        return $labels;
    }

    /**
     * @param  array<mixed>  $tokens
     * @return list<string>
     */
    private function decodeValues(array $tokens): array
    {
        $values = [];

        foreach ($tokens as $token) {
            $value = self::decodeToken($token);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The value as the listing shows it: the column's own state and
     * formatting run against a stand-in record holding just that value.
     * Falls back to the stored value when the column needs more than that.
     */
    private function labelFor(string $raw): string
    {
        try {
            $probe = clone $this->column;
            $probe->record($this->recordHolding($raw));
            $probe->clearCachedState();

            $state = $probe->getState();

            if ($probe instanceof SelectColumn) {
                $state = $probe->getOptions()[$state] ?? $state;
            } elseif (method_exists($probe, 'formatState')) {
                $state = is_array($state)
                    ? array_map(fn (mixed $item): string => self::stringify($probe->formatState($item)), $state)
                    : $probe->formatState($state);
            }

            $label = self::stringify($state);
        } catch (Throwable) {
            $label = '';
        }

        return $label !== '' ? $label : $raw;
    }

    private function recordHolding(string $raw): Model
    {
        $record = $this->model->newInstance();

        // Filament keys its per-record state cache on the record key.
        $record->setAttribute($record->getKeyName(), 'header-filter-probe');

        if ($this->relationshipName === null) {
            $record->setRawAttributes([...$record->getAttributes(), (string) $this->attribute => $raw]);

            return $record;
        }

        $current = $record;

        foreach (explode('.', $this->relationshipName) as $segment) {
            $related = $current->{$segment}()->getRelated()->newInstance();
            $current->setRelation($segment, $related);
            $current = $related;
        }

        $current->setRawAttributes([(string) $this->attribute => $raw]);

        return $record;
    }

    private static function stringify(mixed $state): string
    {
        $text = match (true) {
            $state === null => '',
            is_bool($state) => $state ? 'Yes' : 'No',
            $state instanceof HasLabel => self::stringify($state->getLabel()),
            $state instanceof BackedEnum && method_exists($state, 'label') => (string) $state->label(),
            $state instanceof BackedEnum => (string) $state->value,
            $state instanceof UnitEnum => $state->name,
            $state instanceof Htmlable => html_entity_decode(strip_tags($state->toHtml()), ENT_QUOTES | ENT_HTML5),
            $state instanceof DateTimeInterface => $state->format('d M Y'),
            is_array($state) => implode(', ', array_map(self::stringify(...), $state)),
            is_scalar($state), $state instanceof Stringable => (string) $state,
            default => '',
        };

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
