/*
 * Sidebar modules as an accordion (user decision 2026-11-14).
 *
 * Every module (navigation group) rests collapsed behind its "+" toggle, so
 * the whole module list fits the sidebar without scrolling, and at most one
 * module is open at a time. Opening another module first collapses the open
 * one and only then expands the new one. On every page load and SPA swap the
 * module holding the current page is the one left open.
 *
 * The state is Filament's own $store.sidebar.collapsedGroups, which the
 * published sidebar/group.blade.php view binds to; this script only decides
 * what goes in it. The view falls back to Filament's plain per-group toggle
 * whenever window.fynnSidebarAccordion is missing.
 */
(() => {
    'use strict';

    if (window.fynnSidebarAccordion) {
        return;
    }

    // Matches x-collapse.duration.200ms on .fi-sidebar-group-items in
    // resources/views/vendor/filament-panels/components/sidebar/group.blade.php.
    const COLLAPSE_DURATION = 200;

    // Room left under a just-opened module for the sticky scroll-up chip
    // (.fynn-sidebar-nav-scroll-up) that sits over the bottom of the nav.
    const REVEAL_MARGIN = 40;

    // Read by theme.css ("Sidebar module accordion") to tighten the rows
    // while a module is open. Set on <html>, which Livewire never morphs.
    const OPEN_ITEMS_PROPERTY = '--fynn-nav-open-items';

    let pendingExpand = null;
    let pendingReveal = null;

    function sidebarStore() {
        return window.Alpine?.store('sidebar') ?? null;
    }

    function moduleElements() {
        return Array.from(
            document.querySelectorAll('#fi-main-sidebar .fi-sidebar-group.fi-collapsible[data-group-label]'),
        ).filter((element) => element.dataset.groupLabel !== '');
    }

    function moduleLabels() {
        return moduleElements().map((element) => element.dataset.groupLabel);
    }

    function moduleElement(label) {
        return moduleElements().find((element) => element.dataset.groupLabel === label) ?? null;
    }

    function cancelPending() {
        window.clearTimeout(pendingExpand);
        window.clearTimeout(pendingReveal);
    }

    // The Favourites / Recent sections (App\Support\SidebarShortcuts) are
    // not collapsible: their header and rows always take room, so they
    // count as open rows next to whichever module is open.
    function shortcutRowCount() {
        return Array.from(document.querySelectorAll('#fi-main-sidebar .fi-sidebar-group[data-sidebar-shortcuts]'))
            .reduce((count, element) => count + 1 + element.querySelectorAll('.fi-sidebar-group-items .fi-sidebar-item').length, 0);
    }

    let announcedLabel = null;

    function announceOpenModule(label) {
        announcedLabel = label;

        const itemCount = label === null
            ? 0
            : (moduleElement(label)?.querySelectorAll('.fi-sidebar-group-items .fi-sidebar-item').length ?? 0);

        document.documentElement.style.setProperty(OPEN_ITEMS_PROPERTY, String(itemCount + shortcutRowCount()));
    }

    // Starring a submodule re-renders the sidebar (Livewire), which can grow
    // or shrink the sections without a page load: recount once it settles.
    let pendingRecount = null;

    function watchShortcutSections() {
        new MutationObserver((mutations) => {
            if (! mutations.some((mutation) => mutation.target instanceof Element && mutation.target.closest('#fi-main-sidebar'))) {
                return;
            }

            window.cancelAnimationFrame(pendingRecount);
            pendingRecount = window.requestAnimationFrame(() => announceOpenModule(announcedLabel));
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    // Collapses every module except `label` (none when it is null). Entries
    // for groups outside the main sidebar (sub-navigation) are kept as-is.
    function leaveOnlyOpen(store, label) {
        const labels = moduleLabels();

        store.collapsedGroups = [
            ...(store.collapsedGroups ?? []).filter((collapsed) => ! labels.includes(collapsed)),
            ...labels.filter((moduleLabel) => moduleLabel !== label),
        ];
    }

    // Scrolls the nav just enough to show a module's submodules, never
    // pushing its own header out of the top.
    function reveal(label) {
        const element = moduleElement(label);
        const nav = element?.closest('.fi-sidebar-nav');

        if (! element || ! nav) {
            return;
        }

        const navBox = nav.getBoundingClientRect();
        const box = element.getBoundingClientRect();
        const hiddenBelow = box.bottom + REVEAL_MARGIN - navBox.bottom;

        if (hiddenBelow <= 0) {
            return;
        }

        nav.scrollBy({ top: Math.min(hiddenBelow, box.top - navBox.top), behavior: 'smooth' });
    }

    function expand(store, label) {
        leaveOnlyOpen(store, label);
        pendingReveal = window.setTimeout(() => reveal(label), COLLAPSE_DURATION);
    }

    function toggle(label) {
        const store = sidebarStore();

        if (! store) {
            return;
        }

        cancelPending();

        if (! moduleLabels().includes(label)) {
            store.toggleCollapsedGroup(label);

            return;
        }

        if (! store.groupIsCollapsed(label)) {
            store.collapseGroup(label);
            announceOpenModule(null);

            return;
        }

        // Rows start tightening now, while the open module collapses, so the
        // new one expands into room that is already there.
        announceOpenModule(label);

        const openModules = moduleLabels().filter(
            (moduleLabel) => moduleLabel !== label && ! store.groupIsCollapsed(moduleLabel),
        );

        if (openModules.length === 0) {
            expand(store, label);

            return;
        }

        openModules.forEach((moduleLabel) => store.collapseGroup(moduleLabel));
        pendingExpand = window.setTimeout(() => expand(store, label), COLLAPSE_DURATION);
    }

    // The server marks the module holding the current page .fi-active.
    function openActiveModule() {
        const store = sidebarStore();

        if (! store) {
            return false;
        }

        cancelPending();

        const activeLabel = moduleElements().find((element) => element.classList.contains('fi-active'))?.dataset.groupLabel ?? null;

        announceOpenModule(activeLabel);
        leaveOnlyOpen(store, activeLabel);

        return true;
    }

    window.fynnSidebarAccordion = { toggle, openActiveModule };

    watchShortcutSections();

    // Synced before Alpine first renders the sidebar, so nothing animates on
    // load. Filament registers its `sidebar` store in its own alpine:init
    // listener, which runs before this one; alpine:initialized is the
    // fallback for the case where this script is evaluated first.
    let isSynced = false;

    document.addEventListener('alpine:init', () => {
        isSynced = openActiveModule();
    });

    document.addEventListener('alpine:initialized', () => {
        if (! isSynced) {
            isSynced = openActiveModule();
        }
    });

    // onSwap runs once wire:navigate has put the new page's sidebar in place
    // but before Alpine initialises it, so an SPA visit is as still as a load.
    document.addEventListener('livewire:navigating', (event) => {
        event.detail?.onSwap?.(() => openActiveModule());
    });
})();
