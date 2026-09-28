---
paths:
  - 'app/Providers/Filament/AdminPanelProvider.php, resources/views/vendor/filament-panels/components/sidebar/**, resources/js/sidebar-hover-expand.js, app/Filament/Resources/**/*Resource.php, app/Filament/Pages/*.php'
---

# Filament Pages

## Sidebar is a hover-expanding icon rail; every group and item icon must be unique
Since 2026-09-26 the panel is sidebarCollapsibleOnDesktop() + collapsedSidebarWidth('5rem') (not fully collapsible). resources/js/sidebar-hover-expand.js (FilamentAsset; run `php artisan filament:assets` after editing) opens Filament's $store.sidebar on hover/focus and closes it on leave; the sidebar stays in the page flow, so the content shrinks/grows with it (an earlier overlay variant that floated it over the content was rejected on 2026-09-26 — do not reintroduce it). The topbar expand button pins it open (localStorage fynnon.sidebar-pinned); collapse unpins.

Every NavigationGroup in buildNavigation() carries ->icon(...) and every item its own $navigationIcon, and no icon may be shared between any two entries — tests/Feature/SidebarHoverNavigationTest.php fails on duplicates or missing icons. Pick an unused Heroicon when adding a resource/page/group. Stock Filament throws when a group AND its items have icons; the published resources/views/vendor/filament-panels/components/sidebar/group.blade.php lifts that for groups whose items carry icons (group icon beside the label when open, .fynn-sidebar-group-rail-icon marker above the items when collapsed). Re-diff that file against vendor on Filament upgrades.
