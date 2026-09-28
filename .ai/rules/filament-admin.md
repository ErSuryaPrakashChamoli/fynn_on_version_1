---
paths:
  - 'app/Providers/Filament/AdminPanelProvider.php, resources/views/livewire/⚡top-performer-marquee.blade.php, resources/css/filament/admin/theme.css'
---

# Filament Admin

## Top-performer marquee is a full-width strip under the topbar (TOPBAR_AFTER), not a topbar slot
User decision 2026-09-27: the marquee renders through PanelsRenderHook::TOPBAR_AFTER wrapped in <div class="fynn-marquee-strip"> — a row of the app shell's flex column between the topbar and .fi-layout, spanning sidebar + page, height --fynn-marquee-strip-h (2.25rem). theme.css pushes the desktop .fi-sidebar down by that height with `.fi-body-has-topbar:has(> .fynn-marquee-strip) .fi-sidebar` (IT users get no strip, so no gap). Never put it back inside .fi-topbar-end / GLOBAL_SEARCH_BEFORE with an absolutely positioned wrapper and hard-coded left/right offsets — that is what ran under the period selector once it grew to three dropdowns. The wrapper's own CSS lives in the component's inline <style>; the strip's in theme.css (run `npm run build`).
