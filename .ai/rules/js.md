---
paths:
  - 'resources/js/**'
  - resources/js/login-session-heartbeat.js
---

# Js

## Republish assets after editing a FilamentAsset::register() Js::make() file
Filament copies files registered via `Js::make()`/`FilamentAsset::register()` (see `AdminPanelProvider::boot()`) into `public/js/app/` at publish time and serves that published copy, not `resource_path()` live. After editing one of these files (e.g. `resources/js/table-row-actions.js`), run `php artisan filament:assets` or the browser will keep executing the stale version — no build step or dev-server reload will pick it up.

## The heartbeat may reload at most once per browser session
A 401/404 from /login-session/heartbeat means the login-session row is gone, not that this browser is signed out — so the reloaded page can come back just as authenticated. Reloading again on the next beat is what caused the ~10s refresh loop.

handleSessionEnded() therefore clears BOTH intervals and guards the reload with the `fynnon.session-ended-reload` sessionStorage flag (in sessionStorage because it has to survive the reload it guards; a throwing/blocked store fails closed, i.e. no reload). A healthy heartbeat calls forgetReload() to re-arm it. Never reload straight from the fetch handler.

After editing this file run `php artisan filament:assets` — Filament serves the published copy in public/js/app/.
