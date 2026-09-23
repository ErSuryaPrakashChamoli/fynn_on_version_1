---
paths:
  - app/Providers/Filament/AdminPanelProvider.php
---

# Providers Filament

## Filter apply button closes its panel and returns to the listing
configureFilterApplyBehaviour() registers Table::configureUsing(...->filtersApplyAction(...)) so every deferred-filter table's "Apply" button closes the filter dropdown/modal and scrolls the listing back into view.

Two traps behind the implementation:
- Action::extraAttributes(['x-on:click' => ...]) is silently dropped. Action::toButtonHtml() seeds its attribute bag with an 'x-on:click' key already, and ComponentAttributeBag::merge() lets the existing key win for non-class attributes. Use ->alpineClickHandler() instead.
- ->alpineClickHandler() calls ->livewireClickHandlerEnabled(false) internally, which would strip wire:click="applyTableFilters" and stop filters applying at all. Re-enable it explicitly afterwards.

Tables with ->deferFilters(false) (Employees, Users, EmployeePerformanceReports) have no apply button and are unaffected.

## New resources and pages must be added to buildNavigation()
The /admin sidebar is a hand-written list in AdminPanelProvider::buildNavigation(). discoverResources() only registers routes, so a new resource or page is reachable by URL but appears in nobody's sidebar until you add it via navigationItemsFor(). The resource's $navigationGroup is ignored; the NavigationGroup::make() it is placed in decides where it shows. Cover each addition with a test that GETs /admin and assertSee()s the item's URL. Use followingRedirects(), because the monthly-target gate redirects callers and setters who have no target.
