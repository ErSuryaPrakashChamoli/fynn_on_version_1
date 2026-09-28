---
paths:
  - 'app/Support/Demo/**'
  - 'app/Models/Demo/**'
  - 'app/Http/Middleware/Portal/*Demo*'
  - 'app/Providers/Filament/DemoPanelProvider.php'
  - 'app/Providers/Filament/AdminPanelProvider.php'
  - 'database/seeders/Demo/**'
  - 'config/demo.php'
---

# Demo

## /demo is the SAME admin app on a separate environment — never fork admin code for it
Since 2026-09-24 /demo registers exactly the admin panel's resources, pages, widgets, hooks and theme: DemoPanelProvider extends AdminPanelProvider and both build from configureSharedPanel(). Anything added to app/Filament appears on both panels. Never create Demo* copies of resources/models/services; put panel-wide UI in configureSharedPanel(), and keep panel identity/middleware in each provider's panel(). AdminPanelProvider::boot() registrations are global and must not be repeated (DemoPanelProvider::boot() is its own).

Isolation is the runtime DemoContext (app/Support/Demo/DemoContext.php), switched on first (persistent middleware UseDemoContext) for every /demo request and Livewire round-trip: default DB connection → `demo`, queue → `demo` connection (+ batches/failed jobs), cache path/prefix + Spatie permission cache key, local/public disks under storage/app/{private,public}/demo, mail → log, Telescope off. Session store stays on the main connection on purpose. DemoDatabase::assertIsolated() runs on every activation — keep it.

- Demo logins: DemoUser extends User, pinned to the demo connection, morph class User, guard_name 'web', getForeignKey 'user_id'. Guard `demo`. Demo users/roles/employees exist only in the demo DB.
- Demo DB = normal database/migrations via `php artisan demo:migrate [--fresh] [--seed]` (refuses any migration naming a non-demo connection — Telescope's does, the context repoints it). Never run migrate/db:seed on `demo` directly.
- Queue: jobs from /demo land on the `demo` queue connection (demo DB jobs table). DemoDatabaseQueue::pop() refuses outside the context; the only worker is `php artisan demo:queue-work` (deploy/supervisor/fynn-demo-worker.conf).
- Scheduled jobs get demo counterparts via `demo:run <command>` in routes/console.php. Add one when adding a scheduled command that maintains business data.
- Plain routes outside the panel need a demo copy under the `demo.` route group in routes/web.php (heartbeat, OCR file). Code building their URLs must pick the demo route when DemoContext::isActive() (see OcrDocument::getFileUrlAttribute). Never hardcode `filament.admin.*` route names or `/admin` — use Filament::getLoginUrl()/Resource::getUrl() so the current panel is used.
- Filament export/import download routes are put into the context by DetectDemoContext (?authGuard=demo).

Tests: PortalBoundaryTestCase uses Tests\RefreshDemoDatabase (demo = separate SQLite :memory: with the full schema). Livewire::test() bypasses middleware — wrap demo component tests in DemoContext::run() with Filament::setCurrentPanel('demo'). A test hitting /admin and /demo in one test app sees Filament's scoped navigation from the first panel; hit one panel per test. dispatch() returns a PendingDispatch that pushes on destruct — don't return it out of DemoContext::run(). Covered by tests/Feature/Portal/DemoEnvironmentIsolationTest.php and DemoPanelParityTest.php.

## Topbar module search reads the built sidebar navigation
Since 2026-09-28 the topbar has a "Search modules" button (GLOBAL_SEARCH_BEFORE → filament/components/module-search.blade.php; Ctrl/⌘+K or "/"). Its entries come from App\Support\ModuleSearchIndex, which walks Filament::getNavigation() — i.e. buildNavigation() with its role/IT gates — so anything added to the sidebar is searchable automatically and nothing hidden from a user leaks into search. Filtering is client-side Alpine; styles are inline in the view (no npm build). Keep JS out of HTML entities in the x-data attribute (the browser decodes them). Filament's record globalSearch stays false. Covered by tests/Feature/TopbarModuleSearchTest.php; the Alpine behaviour was verified headlessly.

## Module search panel fills only the page area, not the screen
User decision 2026-09-28: the module-search pop-up is not full-screen — it covers exactly the .fi-main-ctn box (right of the sidebar, below topbar + marquee), measured by place() on open and re-measured by a ResizeObserver (the sidebar widens on hover) and on window resize. z-index 29: above sticky page headers (25) and sidebar (20), below the topbar (30) so its dropdowns open over it. While open, <html> carries .fynn-module-search-open, which hides the floating table scroll arrows (z 30). Modules render as cards in CSS columns on an inner, unbounded box (columns on the fixed-height scroller overflow sideways).
