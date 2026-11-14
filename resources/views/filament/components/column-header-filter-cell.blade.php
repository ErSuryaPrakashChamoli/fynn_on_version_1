{{--
    A listing's column header with an Excel-style dropdown beside the label.
    Rendered through Filament's tables::header.cell hook (see
    App\Support\ColumnHeaderFilters\ColumnHeaderFilters::renderHeaderCell())
    for every column that can be filtered or sorted; any other header stays
    Filament's own. The <th> classes mirror the ones in
    vendor/filament/tables/resources/views/index.blade.php — re-diff them
    against vendor on Filament upgrades.

    User decision 2026-11-14: sorting lives only in the dropdown (Sort A to Z
    / Z to A / Remove sort). The label is not a sort button and carries no
    chevron; the actively sorted column just shows a static arrow.

    The trigger only announces itself; the popover is shared by the whole
    table and lives in column-header-filters.blade.php.

    $filter is null for a column that can be sorted but not filtered.
--}}
@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag;
    use Illuminate\Contracts\Support\Htmlable;

    use function Filament\Support\generate_icon_html;

    $columnName = $column->getName();
    $columnLabel = $column->getLabel();
    $columnAlignment = $column->getAlignment();
    $columnWidth = $column->getWidth();
    $sortDirection = $livewire->getTableSortDirection();
    $isColumnActivelySorted = $livewire->getTableSortColumn() === $columnName;
    $isColumnSortable = $column->isSortable();
    $columnHeaderTooltip = $column->getHeaderTooltip();
    $columnHeaderTooltipAttribute = ($columnHeaderTooltip instanceof Htmlable) ? 'x-tooltip.html' : 'x-tooltip';
    $plainLabel = trim(strip_tags($columnLabel instanceof Htmlable ? $columnLabel->toHtml() : (string) $columnLabel));
    $isFilterable = $filter !== null;
    $triggerLabel = match (true) {
        $isActive => "Change filter on {$plainLabel}",
        $isFilterable => "Sort and filter {$plainLabel}",
        default => "Sort {$plainLabel}",
    };
@endphp

<th
    @if ($isColumnSortable)
        aria-sort="{{ $isColumnActivelySorted ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"
    @endif
    scope="col"
    wire:key="{{ $livewire->getId() }}.table.header-filter-cell.{{ $columnName }}"
    {{
        $column->getExtraHeaderAttributeBag()
            ->class([
                'fi-ta-header-cell',
                'fi-ta-header-cell-' . str($columnName)->camel()->kebab(),
                'fi-growable' => blank($columnWidth) && $column->canGrow(default: false),
                'fi-grouped' => $column->getGroup(),
                'fi-wrapped' => $column->canHeaderWrap(),
                'fi-ta-header-cell-sorted' => $isColumnActivelySorted,
                'fynn-hf-header-cell',
                'fynn-hf-header-cell-filtered' => $isActive,
                (($columnAlignment instanceof Alignment) ? "fi-align-{$columnAlignment->value}" : (is_string($columnAlignment) ? $columnAlignment : '')),
                (filled($columnHiddenFrom = $column->getHiddenFrom()) ? "{$columnHiddenFrom}:fi-hidden" : ''),
                (filled($columnVisibleFrom = $column->getVisibleFrom()) ? "{$columnVisibleFrom}:fi-visible" : ''),
            ])
            ->style([
                ('width: ' . e($columnWidth)) => filled($columnWidth),
            ])
    }}
>
    <span class="fynn-hf-header-cell-content">
        @if (filled($columnHeaderTooltip))
            <span
                {{ $columnHeaderTooltipAttribute }}="{
                    content: @js($columnHeaderTooltip),
                    theme: $store.theme,
                }"
                class="fi-ta-header-cell-tooltip"
            >
                {{ $columnLabel }}
            </span>
        @else
            <span>{{ $columnLabel }}</span>
        @endif

        @if ($isColumnActivelySorted && filled($sortDirection))
            <span
                class="fynn-hf-sort-indicator"
                title="{{ $sortDirection === 'asc' ? 'Sorted ascending' : 'Sorted descending' }}"
            >
                {{ generate_icon_html($sortDirection === 'asc' ? Heroicon::ArrowUp : Heroicon::ArrowDown, attributes: new ComponentAttributeBag(['class' => 'fynn-hf-sort-indicator-icon'])) }}
            </span>
        @endif

        <button
            type="button"
            data-fynn-header-filter-trigger
            data-column="{{ $columnName }}"
            x-on:click.stop.prevent="$dispatch('fynn-header-filter-toggle', {
                tableId: @js($livewire->getId()),
                column: @js($columnName),
                label: @js($plainLabel),
                kind: @js($filter?->kind->value ?? 'sort'),
                sortable: @js($isColumnSortable),
                sortDirection: @js($isColumnActivelySorted ? $sortDirection : null),
                active: @js($isActive),
                trigger: $el,
            })"
            aria-haspopup="dialog"
            aria-label="{{ $triggerLabel }}"
            title="{{ $isActive ? 'Filtered — click to change' : ($isFilterable ? 'Sort & filter' : 'Sort') }}"
            @class([
                'fynn-hf-trigger',
                'fynn-hf-trigger-active' => $isActive,
            ])
        >
            {{ generate_icon_html(match (true) {
                $isActive => Heroicon::Funnel,
                $isFilterable => Heroicon::OutlinedFunnel,
                default => Heroicon::OutlinedChevronUpDown,
            }, attributes: new ComponentAttributeBag(['class' => 'fynn-hf-trigger-icon'])) }}
        </button>
    </span>
</th>
