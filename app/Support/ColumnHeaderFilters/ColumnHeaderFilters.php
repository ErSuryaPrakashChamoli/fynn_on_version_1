<?php

namespace App\Support\ColumnHeaderFilters;

use App\Filament\Concerns\HasColumnHeaderFilters;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Livewire;
use Throwable;

/**
 * Glue between a listing's Livewire component (which holds the header
 * filter state, see HasColumnHeaderFilters) and the Filament table:
 * applies the state to the table query, lists a column's values for its
 * header popover, and renders the header cells and the active-filter chips.
 *
 * Wired up once for every table in AdminPanelProvider::configureColumnHeaderFilters().
 */
final class ColumnHeaderFilters
{
    /**
     * The column whose own filter is left out while its values are listed —
     * Excel lists a column's values under every OTHER column's filter.
     *
     * @var array<int, string>
     */
    private static array $columnsBeingListed = [];

    public static function isEnabledFor(mixed $livewire): bool
    {
        return $livewire instanceof HasTable
            && in_array(HasColumnHeaderFilters::class, class_uses_recursive($livewire), true);
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    public static function applyToQuery(HasTable $livewire, Builder|Relation $query): Builder|Relation
    {
        if (! self::isEnabledFor($livewire)) {
            return $query;
        }

        /** @var array<string, mixed> $state */
        $state = $livewire->tableHeaderFilters ?? [];
        $columnBeingListed = self::$columnsBeingListed[spl_object_id($livewire)] ?? null;

        foreach ($state as $columnName => $columnState) {
            if (! is_string($columnName) || $columnName === $columnBeingListed) {
                continue;
            }

            self::filterFor($livewire, $columnName)?->apply($query, $columnState);
        }

        return $query;
    }

    public static function filterFor(HasTable $livewire, string $columnName): ?ColumnHeaderFilter
    {
        $column = $livewire->getTable()->getColumn($columnName);

        return $column ? ColumnHeaderFilter::for($column) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(HasTable $livewire, string $columnName, ?string $search = null): array
    {
        $filter = self::filterFor($livewire, $columnName);

        if ($filter === null) {
            return ['available' => false, 'message' => 'This column cannot be filtered.'];
        }

        $id = spl_object_id($livewire);
        self::$columnsBeingListed[$id] = $columnName;

        try {
            $query = $livewire->getFilteredTableQuery();
        } finally {
            unset(self::$columnsBeingListed[$id]);
        }

        if ($query === null) {
            return ['available' => false, 'message' => 'This column cannot be filtered.'];
        }

        try {
            return $filter->options($query, $filter->sanitize($livewire->tableHeaderFilters[$columnName] ?? null), $search);
        } catch (Throwable $exception) {
            report($exception);

            return ['available' => false, 'message' => 'The values of this column could not be listed.'];
        }
    }

    /**
     * Column name => chip text, for every header filter that is on.
     *
     * @return array<string, string>
     */
    public static function indicators(HasTable $livewire): array
    {
        if (! self::isEnabledFor($livewire)) {
            return [];
        }

        $indicators = [];

        foreach ($livewire->tableHeaderFilters ?? [] as $columnName => $columnState) {
            if (! is_string($columnName)) {
                continue;
            }

            try {
                $indicator = self::filterFor($livewire, $columnName)?->indicator($columnState);
            } catch (Throwable $exception) {
                report($exception);

                $indicator = null;
            }

            if (filled($indicator)) {
                $indicators[$columnName] = $indicator;
            }
        }

        return $indicators;
    }

    /**
     * The header cell of a column that can be filtered or sorted — sorting
     * is offered only from its dropdown. '' leaves the cell to Filament
     * (plain columns, reordering, components without the trait).
     */
    public static function renderHeaderCell(Column $column, bool $isReordering = false): string
    {
        if ($isReordering) {
            return '';
        }

        $livewire = $column->getTable()->getLivewire();

        if (! self::isEnabledFor($livewire)) {
            return '';
        }

        $filter = ColumnHeaderFilter::for($column);

        if ($filter === null && ! $column->isSortable()) {
            return '';
        }

        return view('filament.components.column-header-filter-cell', [
            'column' => $column,
            'filter' => $filter,
            'livewire' => $livewire,
            'isActive' => $filter?->sanitize($livewire->tableHeaderFilters[$column->getName()] ?? null) !== null,
        ])->render();
    }

    /**
     * The active-filter chips and the shared header popover, rendered once
     * per table under its toolbar.
     */
    public static function renderToolbar(): string
    {
        $livewire = Livewire::current();

        if (! self::isEnabledFor($livewire)) {
            return '';
        }

        return view('filament.components.column-header-filters', [
            'livewire' => $livewire,
            'indicators' => self::indicators($livewire),
        ])->render();
    }
}
