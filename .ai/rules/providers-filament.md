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
The /admin sidebar is a hand-written list in AdminPanelProvider::buildNavigation(). discoverResources() only registers routes, so a new resource or page is reachable by URL but appears in nobody's sidebar until you add it via navigationItemsFor(). The resource's $navigationGroup is ignored; the NavigationGroup::make() it is placed in decides where it shows. User decisions 2026-11-14: Dashboard on top, then nine modules A to Z (sixteen were merged; insert a new module at its alphabetical position). Submodules are sorted A to Z inside each module at runtime and the Favourites (starred submodules, below Dashboard) and Recent (last 5 opened, at the bottom) sections are added by App\Support\SidebarShortcuts::arrange() — never hand-order submodules or add those sections yourself. Merging modules never widens access because navigationItemsFor() checks every submodule; SidebarModuleAccordionTest asserts the order and per-role visibility. Cover each addition with a test that GETs /admin and assertSee()s the item's URL. Use followingRedirects(), because the monthly-target gate redirects callers and setters who have no target.

## The navigation is built once per request
Filament calls the navigation builder every time anything asks for Filament::getNavigation() (sidebar, topbar, ModuleSearchIndex, SidebarFavourites…), which was seven full builds per page, each running every submodule's canAccess() and badge count queries (2026-11-21). navigationGroupsForRequest() keeps the built groups on request()->attributes, keyed by panel id, user id and the user's favourites, so a new HTTP/Livewire request always rebuilds and starring rebuilds at once. Badge counts (getNavigationBadge()) therefore run once per request: keep them cheap single counts, never per-row work. In a test that needs a second build in the same test method, swap the request (`$this->app->instance('request', Request::create('/admin'))`). Covered by tests/Feature/NavigationBuiltOncePerRequestTest.php.
