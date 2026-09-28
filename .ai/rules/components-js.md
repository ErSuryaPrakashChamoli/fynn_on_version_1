---
paths:
  - 'resources/css/filament/admin/theme.css, resources/views/filament/components/table-scroll-to-top.blade.php, resources/js/table-scroll-nav.js'
---

# Components Js

## Listings have ONE vertical scrollbar (the page); table body is unbounded, floating arrows scroll the page
User decision 2026-09-27: a listing must show only the page's vertical scrollbar (.fi-main-ctn). The max-height:65dvh / overflow-y:auto bound on .fi-ta-content-ctn (the "sticky table header" block) is removed — do not reintroduce it. Consequence: the thead sticky rule is inert (wrapper stays overflow-x:auto for wide tables, which is the header's scroll container and no longer scrolls vertically); a sticky header needs the bound back or no horizontal scroll, so it is a trade-off to raise with the user first. The old "Back to top" button is gone: filament/components/table-scroll-to-top.blade.php (RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER hook) renders fixed-position up/down arrows that pick whichever box overflows (table body → .fi-main-ctn → document) and anchor to the last .fi-ta .fi-pagination bar so they never cover row-action buttons. Livewire::test() does not boot panel render hooks — test listing hooks with a real GET (tests/Feature/ListingScrollToTopTest.php). After editing theme.css run `npm run build`.
