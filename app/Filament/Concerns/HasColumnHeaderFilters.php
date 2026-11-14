<?php

namespace App\Filament\Concerns;

use App\Support\ColumnHeaderFilters\ColumnHeaderFilters;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Renderless;

/**
 * Excel-style filters in a listing's column headers: every filterable
 * column header gets a funnel that opens sort options, a search box and a
 * checklist of the column's values (or a date range).
 *
 * Every Livewire component that renders a Filament table uses this trait
 * (tests/Feature/ColumnHeaderFiltersTest.php fails on one that does not).
 * The trait only holds the state and the actions the popover calls; which
 * filter a column gets and how it queries lives in
 * App\Support\ColumnHeaderFilters, and AdminPanelProvider wires the state
 * into every table's query and header.
 */
trait HasColumnHeaderFilters
{
    /**
     * Column name => that column's filter, e.g.
     * ['status' => ['values' => ['r:Pending']], 'next_follow_up_date' => ['from' => '2026-10-01']].
     *
     * @var array<string, array<string, mixed>>
     */
    public array $tableHeaderFilters = [];

    /**
     * What the header popover of one column shows. Renderless: listing the
     * values must not redraw the table under the open popover.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function getTableHeaderFilterOptions(string $column, ?string $search = null): array
    {
        return ColumnHeaderFilters::options($this, $column, $search);
    }

    /**
     * @param  array<string, mixed>|null  $state  null clears the column's filter.
     */
    public function applyTableHeaderFilter(string $column, ?array $state = null): void
    {
        $state = ColumnHeaderFilters::filterFor($this, $column)?->sanitize($state);

        $filters = $this->tableHeaderFilters;

        if ($state === null) {
            unset($filters[$column]);
        } else {
            $filters[$column] = $state;
        }

        $this->tableHeaderFilters = $filters;

        $this->handleTableHeaderFilterUpdates();
    }

    public function removeTableHeaderFilter(string $column): void
    {
        $this->applyTableHeaderFilter($column, null);
    }

    public function removeTableHeaderFilters(): void
    {
        $this->tableHeaderFilters = [];

        $this->handleTableHeaderFilterUpdates();
    }

    /**
     * For counts a page works out beside the table (e.g. tab badges) that
     * should agree with what the table lists.
     *
     * @template TQuery of Builder<Model>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function applyTableHeaderFiltersToQuery(Builder $query): Builder
    {
        ColumnHeaderFilters::applyToQuery($this, $query);

        return $query;
    }

    protected function handleTableHeaderFilterUpdates(): void
    {
        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->resetPage();
    }

    /**
     * Full pages keep the header filters in the URL beside Filament's own
     * filters, search and sort, so Back returns to the same filtered list.
     * Relation managers and widgets share their page with other tables and
     * keep theirs in the component only.
     *
     * @return array<string, array{as: string, except: array<never>}>
     */
    protected function queryStringHasColumnHeaderFilters(): array
    {
        if (! $this instanceof Page) {
            return [];
        }

        return ['tableHeaderFilters' => ['as' => 'columnFilters', 'except' => []]];
    }
}
