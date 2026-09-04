---
paths:
  - 'app/Filament/**'
---

# Filament

## Dropdowns are searchable panel-wide by default — don't repeat ->searchable()
AdminPanelProvider::configureSearchableDropdowns() registers Select::configureUsing(searchable()) and SelectFilter::configureUsing(searchable()->preload()) (TernaryFilter excluded). Every form Select and table SelectFilter therefore gets a type-to-filter search box without opting in per component; an explicit ->searchable(false) on a component still wins because configureUsing runs at construction.

Consequence to watch: a Select bound to ->relationship() that is searchable but NOT preloaded returns no options until the user types (Select::getOptionsFromRelationship() returns null). Add ->preload() when the list is short and should show immediately.

tests/Feature/AllListingsFiltersAndDropdownsTest.php asserts every resource index renders, every SelectFilter on it produces a runnable query, and every one of them is searchable.
