(() => {
    'use strict';

    if (window.__fynnSidebarHoverExpandInitialized) {
        return;
    }

    window.__fynnSidebarHoverExpandInitialized = true;

    // Mirrors the `breakpoint` Filament's own sidebar store uses to tell a
    // desktop layout (collapsible icon rail) from a mobile one (off-canvas
    // drawer). Below it the drawer keeps Filament's tap-to-toggle behaviour.
    const DESKTOP_BREAKPOINT = 1024;

    // Set by the topbar's own expand/collapse buttons: "expand" pins the
    // sidebar open so hovering no longer collapses it; "collapse" hands it
    // back to hover mode. Kept in localStorage like Filament's own isOpen.
    const PIN_STORAGE_KEY = 'fynnon.sidebar-pinned';

    // Short grace period before collapsing so the rail doesn't flicker when
    // the cursor grazes its edge or crosses the gap into a tooltip.
    const COLLAPSE_DELAY = 180;

    let collapseTimer = null;

    function sidebarStore() {
        return window.Alpine?.store('sidebar') ?? null;
    }

    function sidebarElement() {
        return document.getElementById('fi-main-sidebar');
    }

    function isHoverMode() {
        return (
            window.innerWidth >= DESKTOP_BREAKPOINT &&
            window.matchMedia('(hover: hover)').matches &&
            document.body.classList.contains('fi-body-has-sidebar-collapsible-on-desktop')
        );
    }

    function isPinned() {
        try {
            return window.localStorage.getItem(PIN_STORAGE_KEY) === '1';
        } catch {
            return false;
        }
    }

    function setPinned(pinned) {
        try {
            window.localStorage.setItem(PIN_STORAGE_KEY, pinned ? '1' : '0');
        } catch {
            // Storage blocked: the sidebar simply stays in hover mode.
        }
    }

    function expand() {
        window.clearTimeout(collapseTimer);

        const store = sidebarStore();

        if (! store) {
            return;
        }

        if (store.isOpen) {
            return;
        }

        store.open();
    }

    function collapse() {
        window.clearTimeout(collapseTimer);

        const store = sidebarStore();

        if (! store) {
            return;
        }

        if (store.isOpen) {
            store.close();
        }
    }

    function scheduleCollapse() {
        window.clearTimeout(collapseTimer);
        collapseTimer = window.setTimeout(collapse, COLLAPSE_DELAY);
    }

    // The state the sidebar rests in when nothing is hovering it: collapsed
    // to the icon rail, unless the user pinned it open from the topbar.
    function applyRestingState() {
        if (! isHoverMode()) {
            return;
        }

        if (isPinned()) {
            sidebarStore()?.open();

            return;
        }

        if (sidebarElement()?.matches(':hover')) {
            expand();

            return;
        }

        collapse();
    }

    function isInsideSidebar(node) {
        const sidebar = sidebarElement();

        return Boolean(sidebar && node instanceof Node && sidebar.contains(node));
    }

    // Delegated from the document so the handlers survive Livewire
    // re-rendering the sidebar and wire:navigate swapping the page.
    document.addEventListener('mouseover', (event) => {
        if (! isHoverMode() || isPinned() || ! isInsideSidebar(event.target)) {
            return;
        }

        expand();
    });

    document.addEventListener('mouseout', (event) => {
        if (! isHoverMode() || isPinned() || ! isInsideSidebar(event.target)) {
            return;
        }

        if (isInsideSidebar(event.relatedTarget)) {
            return;
        }

        scheduleCollapse();
    });

    // Keyboard users tabbing into the rail get the labels too.
    document.addEventListener('focusin', (event) => {
        if (! isHoverMode() || isPinned() || ! isInsideSidebar(event.target)) {
            return;
        }

        expand();
    });

    document.addEventListener('focusout', (event) => {
        if (! isHoverMode() || isPinned() || ! isInsideSidebar(event.target)) {
            return;
        }

        if (isInsideSidebar(event.relatedTarget)) {
            return;
        }

        scheduleCollapse();
    });

    /*
     * The pin control: a round chevron that hangs on the rail's right edge,
     * level with the Dashboard (home) item, and fades in while the sidebar
     * is hovered (user decision 2026-09-27 — the topbar's own expand /
     * collapse buttons are hidden on desktop in theme.css). Click pins the
     * sidebar open; click again hands it back to hover mode. Injected here
     * rather than in a published view because the sidebar is a Livewire
     * component whose re-renders would drop it — ensurePinButton() puts it
     * back whenever that happens.
     */
    const PIN_BUTTON_CLASS = 'fynn-sidebar-pin-btn';

    const CHEVRON_LEFT = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>';

    const CHEVRON_RIGHT = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';

    let pinButtonFrame = null;

    function dashboardItem() {
        return sidebarElement()?.querySelector('.fi-sidebar-nav .fi-sidebar-item') ?? null;
    }

    function renderPinButton(button) {
        const pinned = isPinned();

        button.innerHTML = pinned ? CHEVRON_LEFT : CHEVRON_RIGHT;
        button.setAttribute('aria-label', pinned ? 'Collapse sidebar' : 'Keep sidebar open');
        button.setAttribute('title', pinned ? 'Collapse sidebar' : 'Keep sidebar open');
        button.setAttribute('aria-pressed', pinned ? 'true' : 'false');
        button.classList.toggle('is-pinned', pinned);
    }

    function placePinButton() {
        pinButtonFrame = null;

        const sidebar = sidebarElement();
        const button = sidebar?.querySelector(`:scope > .${PIN_BUTTON_CLASS}`);

        if (! sidebar || ! button) {
            return;
        }

        const hoverMode = isHoverMode();
        button.classList.toggle('is-available', hoverMode);

        if (! hoverMode) {
            return;
        }

        const item = dashboardItem();

        if (! item) {
            return;
        }

        const itemRect = item.getBoundingClientRect();
        const sidebarRect = sidebar.getBoundingClientRect();
        const top = itemRect.top - sidebarRect.top + (itemRect.height - button.offsetHeight) / 2;

        button.style.top = `${Math.round(top)}px`;
        renderPinButton(button);
    }

    function schedulePinButtonPlacement() {
        if (pinButtonFrame === null) {
            pinButtonFrame = window.requestAnimationFrame(placePinButton);
        }
    }

    function togglePin() {
        const pinned = ! isPinned();

        window.clearTimeout(collapseTimer);
        setPinned(pinned);

        if (pinned) {
            sidebarStore()?.open();
        } else {
            collapse();
        }

        schedulePinButtonPlacement();
    }

    function ensurePinButton() {
        const sidebar = sidebarElement();

        if (! sidebar) {
            return;
        }

        if (! sidebar.querySelector(`:scope > .${PIN_BUTTON_CLASS}`)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = PIN_BUTTON_CLASS;
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                togglePin();
            });

            sidebar.appendChild(button);

            // The nav scrolls independently: keep the button on the row.
            sidebar.querySelector('.fi-sidebar-nav')?.addEventListener('scroll', schedulePinButtonPlacement, { passive: true });
        }

        schedulePinButtonPlacement();
    }

    let sidebarObserver = null;

    function watchSidebar() {
        sidebarObserver?.disconnect();

        const sidebar = sidebarElement();

        if (! sidebar) {
            return;
        }

        sidebarObserver = new MutationObserver(ensurePinButton);
        sidebarObserver.observe(sidebar, { childList: true, subtree: true });

        ensurePinButton();
    }

    // Filament's own toggle buttons keep working; they now also decide
    // whether the sidebar is pinned. Runs after the buttons' Alpine handlers
    // (bubbling), so the store already reflects the click.
    document.addEventListener('click', (event) => {
        if (! (event.target instanceof Element)) {
            return;
        }

        if (event.target.closest('.fi-topbar-open-collapse-sidebar-btn, .fi-sidebar-open-collapse-sidebar-btn')) {
            window.clearTimeout(collapseTimer);
            setPinned(true);

            return;
        }

        if (event.target.closest('.fi-topbar-close-collapse-sidebar-btn, .fi-sidebar-close-collapse-sidebar-btn')) {
            setPinned(false);
        }

        schedulePinButtonPlacement();
    });

    // Filament registers its `sidebar` store inside its own alpine:init
    // listener, which runs before this one; alpine:initialized is the
    // fallback for the case where this script is evaluated first.
    document.addEventListener('alpine:init', () => {
        if (sidebarStore()) {
            applyRestingState();
        }
    });

    document.addEventListener('alpine:initialized', () => {
        applyRestingState();
        watchSidebar();
    });

    document.addEventListener('livewire:navigated', () => {
        applyRestingState();
        watchSidebar();
    });

    window.addEventListener('resize', () => {
        applyRestingState();
        schedulePinButtonPlacement();
    }, { passive: true });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchSidebar);
    } else {
        watchSidebar();
    }
})();
