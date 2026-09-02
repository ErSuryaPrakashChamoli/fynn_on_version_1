---
paths:
  - 'resources/js/**'
---

# Js

## Republish assets after editing a FilamentAsset::register() Js::make() file
Filament copies files registered via `Js::make()`/`FilamentAsset::register()` (see `AdminPanelProvider::boot()`) into `public/js/app/` at publish time and serves that published copy, not `resource_path()` live. After editing one of these files (e.g. `resources/js/table-row-actions.js`), run `php artisan filament:assets` or the browser will keep executing the stale version — no build step or dev-server reload will pick it up.
