---
paths:
  - 'app/Filament/**, app/Support/ColumnHeaderFilters/**, app/Enums/ColumnHeaderFilterKind.php'
  - 'resources/js/column-header-filter.js, resources/css/column-header-filters.css, resources/views/filament/components/column-header-filter*.blade.php'
---

# Column Header Filters

## Every table has Excel-style header filters; sorting lives only in the header dropdown
User decision 2026-11-14: every listing header offers an Excel-style dropdown (funnel) with Sort A→Z / Z→A / Remove sort, a search box and a checklist of the column's values (date columns: from/to range). Sorting is NOT on the label any more — no chevron, no click-to-sort; the sorted column shows a static arrow. Sortable-but-unfilterable columns get the same dropdown with sort options only.

Every Livewire component that renders a Filament table must `use App\Filament\Concerns\HasColumnHeaderFilters;` (ColumnHeaderFiltersTest fails otherwise) — subclasses of an app table class inherit it. The trait only holds `$tableHeaderFilters` and the popover's actions; AdminPanelProvider::configureColumnHeaderFilters() applies the state as a Table query scope (so pagination, exports and select-all honour it; skipped while resolving a row action's record) and renders the cells (tables::header.cell) and chips + shared popover (tables::toolbar.after). Pages keep the state in the URL as `columnFilters`; relation managers/widgets do not.

What a column gets is derived (ColumnHeaderFilter::for): real DB column or relationship attribute → value checklist / date range; a column with a state() closure is never filtered by its DB name; a computed column with a same-named SelectFilter → that filter's options and query; a computed searchable column → "contains"; otherwise nothing. Counts shown beside a table must also go through applyTableHeaderFiltersToQuery() (see FollowUpOutcomeListPage::outcomeCount).

Trap: values reach the browser only as tokens — "r:value" when the label equals the stored value, otherwise "e:" + Crypt::encryptString(), so masked columns (mobile_no → XXXXXX1234) never leak raw values. The header cell copies Filament's <th> classes — re-diff on Filament upgrades. The JS and CSS are FilamentAssets (CSS is plain, not in theme.css, because the Academy panel does not load the admin theme): run `php artisan filament:assets` after editing either.
