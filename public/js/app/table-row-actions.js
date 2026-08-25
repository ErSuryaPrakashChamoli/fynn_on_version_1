(() => {
    'use strict';

    if (window.__fynnTableRowActionsInitialized) {
        return;
    }

    window.__fynnTableRowActionsInitialized = true;

    // Delay before a single click is treated as "activate the row". A
    // dblclick (fired natively by the browser on an actual double-click,
    // well within this delay) cancels the pending activation and opens
    // the record instead -- see the click/dblclick listeners below.
    const CLICK_DELAY = 200;

    // Elements whose own click behaviour must never be hijacked: checkboxes,
    // selects, the row's own (now-expanded-into) action buttons/links --
    // they live inside `.fi-ta-actions` -- and anything inside an open
    // overlay.
    //
    // Deliberately NOT included here: plain `<a>`/`<button>` tags in
    // general. Filament's `ListRecords` page gives every table a *default*
    // `recordUrl` pointing at the record's View/Edit page whenever a
    // resource doesn't set its own (`vendor/filament/filament/src/
    // Resources/Pages/ListRecords.php`) -- none of this app's tables set
    // one explicitly, so every data cell (`.fi-ta-col`) already renders as
    // an `<a href="...record...">` wrapping its content. That's exactly
    // the click surface this interaction model needs to govern (single
    // click activates, double click opens), so it must NOT be treated as
    // "leave it alone" -- its default navigation is prevented below and
    // replaced with the click/dblclick timer logic.
    const NATIVE_INTERACTIVE_SELECTOR = 'input, select, textarea, label, [contenteditable="true"]';

    // The row's own actions area (and anything inside it) is always left
    // alone -- its buttons/links are the real thing.
    const ACTIONS_BAR_SELECTOR = '.fi-ta-actions';

    // Anything that should be treated as "still interacting with the row's
    // own tools" for the purposes of the outside-click-closes behaviour:
    // the row's actions area itself, plus any open Filament modal,
    // dropdown, or select/combobox panel a bar action might have opened.
    const PROTECTED_OVERLAY_SELECTOR = '[class*="fi-modal"], [class*="fi-dropdown"], [role="dialog"], [role="menu"], [role="listbox"]';

    let activeRow = null;

    // Set for the duration of a single synchronous `link.click()` call in
    // `openRecord()`, so that synthetic click is let straight through
    // instead of being intercepted again by the capture-phase listeners
    // below (see the comment on `openRecord()`).
    let suppressInterception = false;

    function isRecordRow(el) {
        return el instanceof HTMLElement && el.matches('tr.fi-ta-row');
    }

    function getActionsCell(row) {
        return row.querySelector(':scope > td:has(> .fi-ta-actions)');
    }

    function getActionsEl(row) {
        return row.querySelector(':scope > td > .fi-ta-actions');
    }

    // Expands the row (extra bottom padding on its cells, which pushes
    // every row below it further down the page -- a real layout change,
    // not a floating overlay) and reveals the actions bar inside that
    // newly opened space, docked to the bottom-left of the row. Because
    // the space is actually reserved, this can never cover -- or steal
    // clicks from -- the row underneath, unlike a floating popover would.
    // Left inset for the actions bar within the expanded space, so it
    // doesn't sit flush against the table's edge.
    const ROW_EXPAND_LEFT_INSET = 20;

    function expandRow(row, cell) {
        const rowRect = row.getBoundingClientRect();

        // Reveal invisibly first, at the width it will actually render at,
        // to measure how tall it naturally wraps to (action count differs
        // per row/table) before reserving exactly that much space.
        cell.style.visibility = 'hidden';
        cell.style.display = 'block';
        cell.style.position = 'absolute';
        cell.style.top = '0px';
        cell.style.left = `${ROW_EXPAND_LEFT_INSET}px`;
        cell.style.bottom = '';
        cell.style.right = '';
        cell.style.maxWidth = `${Math.max(200, Math.round(rowRect.width - ROW_EXPAND_LEFT_INSET - 16))}px`;

        const barHeight = cell.offsetHeight;
        const gap = 10;

        row.style.setProperty('--fynn-row-expand', `${barHeight + gap}px`);

        // `bottom: 0` on the (now absolutely positioned) cell, inside the
        // row's own (now taller) box, tracks the growing edge natively as
        // the CSS `padding-bottom` transition plays -- no JS animation.
        cell.style.top = '';
        cell.style.bottom = '0px';
        cell.style.left = `${ROW_EXPAND_LEFT_INSET}px`;
        cell.style.visibility = '';
    }

    function activateRow(row) {
        const actionsEl = getActionsEl(row);

        if (!actionsEl || actionsEl.children.length === 0) {
            // Nothing to show for this record (e.g. every action is hidden
            // for it) -- still close whatever was previously active.
            deactivateRow(activeRow);

            return;
        }

        if (activeRow && activeRow !== row) {
            deactivateRow(activeRow);
        }

        row.classList.add('fi-ta-row-active');
        activeRow = row;

        expandRow(row, getActionsCell(row));
    }

    function deactivateRow(row) {
        if (!row) {
            return;
        }

        row.classList.remove('fi-ta-row-active');
        row.style.removeProperty('--fynn-row-expand');

        const cell = getActionsCell(row);

        if (cell) {
            cell.style.top = '';
            cell.style.bottom = '';
            cell.style.left = '';
            cell.style.right = '';
            cell.style.maxWidth = '';
            cell.style.visibility = '';
        }

        if (activeRow === row) {
            activeRow = null;
        }
    }

    // Every data cell's content is wrapped in an `<a>` pointing at the
    // record's default URL (see the NATIVE_INTERACTIVE_SELECTOR comment
    // above), and Filament renders that anchor with an inline Alpine
    // `x-on:click` handler (`generate_href_html(..., hasNestedClickEventHandler:
    // true)` in `vendor/filament/support/src/helpers.php`) that calls
    // `Alpine.navigate(href)` *itself* -- it doesn't rely on the browser's
    // default "follow href" behaviour. That handler is bound directly on
    // the anchor and fires at the target phase, before a bubble-phase
    // listener on `document` would ever see the event -- so calling
    // `preventDefault()` from a bubble-phase listener is too late to stop
    // it. Listening in the CAPTURE phase (the `true` third argument below)
    // runs before the event reaches the anchor at all, so
    // `stopPropagation()` there reliably stops Alpine's handler from
    // running, letting this interaction model own the click instead.
    function openRecord(row) {
        // Prefer the row's own default record link so the exact same
        // View/Edit page Filament would already navigate to on a plain
        // click is reused untouched. Falls back to the first link inside
        // the actions bar for a resource with no default record link
        // (e.g. no dedicated view/edit page) but an explicit action that
        // has a URL.
        const link = row.querySelector('a.fi-ta-col[href]') || getActionsEl(row)?.querySelector('a[href]');

        if (!link) {
            return;
        }

        // Let this one synthetic click bypass the capture listeners below
        // entirely, so it reaches the anchor's own Alpine/Livewire
        // navigation handling exactly as if the user had clicked it
        // directly -- no navigation logic is duplicated here.
        suppressInterception = true;
        link.click();
        suppressInterception = false;
    }

    function handleRowClick(event) {
        if (suppressInterception) {
            return;
        }

        if (event.target.closest(NATIVE_INTERACTIVE_SELECTOR) || event.target.closest(ACTIONS_BAR_SELECTOR)) {
            return;
        }

        const row = event.target.closest('tr.fi-ta-row');

        if (!isRecordRow(row)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        window.clearTimeout(row._fynnRowClickTimer);
        row._fynnRowClickTimer = window.setTimeout(() => {
            // Clicking the row that's already expanded closes it again,
            // rather than just re-opening it, as a normal toggle.
            if (row === activeRow) {
                deactivateRow(row);
            } else {
                activateRow(row);
            }
        }, CLICK_DELAY);
    }

    function handleRowDblClick(event) {
        if (suppressInterception) {
            return;
        }

        if (event.target.closest(NATIVE_INTERACTIVE_SELECTOR) || event.target.closest(ACTIONS_BAR_SELECTOR)) {
            return;
        }

        const row = event.target.closest('tr.fi-ta-row');

        if (!isRecordRow(row)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        window.clearTimeout(row._fynnRowClickTimer);
        openRecord(row);
    }

    // Close on a genuine "outside" interaction, but never while the user is
    // still working inside the active row (its own actions area counts,
    // since it's a real DOM descendant of the row) or inside a
    // modal/dropdown/select panel that a bar action opened.
    document.addEventListener('pointerdown', (event) => {
        if (!activeRow) {
            return;
        }

        if (activeRow.contains(event.target)) {
            return;
        }

        if (event.target.closest(PROTECTED_OVERLAY_SELECTOR)) {
            return;
        }

        deactivateRow(activeRow);
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeRow) {
            const row = activeRow;
            deactivateRow(row);
            row.focus();

            return;
        }

        if (event.key !== 'Enter') {
            return;
        }

        const row = event.target.closest('tr.fi-ta-row');

        if (!isRecordRow(row) || event.target !== row) {
            return;
        }

        openRecord(row);
    });

    document.addEventListener('focusin', (event) => {
        const row = event.target.closest('tr.fi-ta-row');

        if (isRecordRow(row) && event.target === row) {
            activateRow(row);
        }
    });

    window.addEventListener('resize', () => {
        if (activeRow) {
            deactivateRow(activeRow);
        }
    });

    // Table rows aren't natively focusable; give every rendered row
    // `tabindex="0"` so keyboard users can Tab onto it (which activates it,
    // same as a click) and from there Tab into its now-visible action
    // buttons. Observing `document.body` (rather than a captured
    // `#fi-main-content` reference) means this keeps working even if
    // Livewire/Alpine ever replaces that element outright (e.g. restoring
    // a cached page on browser back/forward) rather than just swapping its
    // children -- `document.body` itself is never replaced short of a full
    // page reload, which would re-run this whole script fresh anyway.
    function makeRowsFocusable(root) {
        root.querySelectorAll('tr.fi-ta-row:not([tabindex])').forEach((row) => {
            row.tabIndex = 0;
        });
    }

    function observe(root) {
        makeRowsFocusable(root);

        new MutationObserver(() => makeRowsFocusable(root)).observe(root, {
            childList: true,
            subtree: true,
        });
    }

    function init() {
        // Bound to `document` itself rather than a captured element
        // reference for the same reason `observe()` below uses
        // `document.body`: Livewire's `wire:navigate` history handling can
        // restore a previous page's content (e.g. on browser back) by
        // replacing an element like `#fi-main-content` outright rather
        // than morphing its children in place, which would silently
        // detach listeners bound to the old (captured) node -- clicks
        // would then fall straight through to Alpine's own navigate
        // handler with nothing intercepting them. `document` is never
        // replaced short of a full page reload.
        document.addEventListener('click', handleRowClick, true);
        document.addEventListener('dblclick', handleRowDblClick, true);

        observe(document.body);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
