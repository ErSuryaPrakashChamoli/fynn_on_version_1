(() => {
    'use strict';

    if (window.__fynnListingStickyHeaderInitialized) {
        return;
    }

    window.__fynnListingStickyHeaderInitialized = true;

    /*
     * Freezes the top of every panel page while its content scrolls:
     *
     *  - the page header (breadcrumbs, title, header actions) is
     *    position: sticky in CSS (theme.css, "Frozen page header"); this
     *    script only measures its height into --fynn-sticky-header-h on
     *    .fi-page so the next layer knows where to stop;
     *  - on resource List pages the table's header block (.fi-ta-header-ctn:
     *    header actions + the per page / search / filters toolbar) is
     *    sticky in CSS at that height;
     *  - custom pages built as a listing opt in with .fynn-frozen-listing-page
     *    and may freeze a block above the table (.fynn-frozen-listing-top:
     *    filters, tabs) under the page header; its height goes into
     *    --fynn-frozen-top-h and the table's header block stops below it;
     *  - the table's column header row cannot be CSS-sticky against the
     *    page: .fi-ta-content-ctn is overflow-x:auto for wide tables, which
     *    makes IT the header's scroll container, and it no longer scrolls
     *    vertically (listings have one scrollbar — the page's). So on every
     *    scroll tick the <th> cells get a translateY equal to how far the
     *    table's top has passed under the frozen toolbar, which keeps them
     *    pinned exactly like a sticky header would.
     *
     * Scroll events are captured on window so it works whichever element
     * scrolls (.fi-main-ctn is the panel's scroll region).
     */

    const LIST_PAGE_CLASS = 'fi-resource-list-records-page';
    const FROZEN_LISTING_CLASS = 'fynn-frozen-listing-page';
    const FROZEN_TOP_SELECTOR = '.fynn-frozen-listing-top';

    let frame = null;
    let pageObserver = null;
    let sizeObserver = null;

    function scroller() {
        return document.querySelector('.fi-body-has-navigation .fi-main-ctn');
    }

    function currentPage() {
        return document.querySelector('.fi-page');
    }

    function isListing(page) {
        return page.classList.contains(LIST_PAGE_CLASS) || page.classList.contains(FROZEN_LISTING_CLASS);
    }

    // The element theme.css made sticky for the page's frozen block: the
    // block itself or the schema wrapper around it. None on small screens,
    // where the block scrolls away with the page.
    function frozenTop(page) {
        for (let node = page.querySelector(FROZEN_TOP_SELECTOR); node && node !== page; node = node.parentElement) {
            if (getComputedStyle(node).position === 'sticky') {
                return node;
            }
        }

        return null;
    }

    function pinHeaderRow(table, stickyLine) {
        const toolbar = table.querySelector('.fi-ta-header-ctn') ?? table.querySelector('.fi-ta-header-toolbar');
        const toolbarRect = toolbar ? toolbar.getBoundingClientRect() : null;
        const toolbarHeight = toolbarRect ? toolbarRect.height : 0;

        // Toolbar dropdowns (filters, column manager) ride along with the
        // frozen toolbar, so theme.css caps them at the space below it.
        if (toolbarRect) {
            table.style.setProperty('--fynn-toolbar-bottom', `${Math.round(Math.max(0, toolbarRect.bottom))}px`);
        }

        const box = table.querySelector('.fi-ta-content-ctn');
        const thead = box?.querySelector('thead');

        if (! box || ! thead) {
            return;
        }

        const boxRect = box.getBoundingClientRect();
        const theadHeight = thead.getBoundingClientRect().height;

        // How far the table's top has slid under the frozen toolbar, never
        // past the table's own end (the header must not leave the table).
        let offset = (stickyLine + toolbarHeight) - boxRect.top;
        offset = Math.max(0, Math.min(offset, boxRect.height - theadHeight));
        offset = Math.round(offset);

        const value = offset > 0 ? `translateY(${offset}px)` : '';

        thead.querySelectorAll('th').forEach((cell) => {
            if (cell.style.transform !== value) {
                cell.style.transform = value;
            }
        });

        thead.classList.toggle('fynn-thead-pinned', offset > 0);
    }

    function update() {
        frame = null;

        const page = currentPage();

        if (! page) {
            return;
        }

        const header = page.querySelector('.fi-page-header-main-ctn > .fi-header');
        const headerHeight = header ? header.getBoundingClientRect().height : 0;

        page.style.setProperty('--fynn-sticky-header-h', `${Math.round(headerHeight)}px`);

        if (! isListing(page)) {
            return;
        }

        const frozenBlock = frozenTop(page);
        const frozenHeight = frozenBlock ? frozenBlock.getBoundingClientRect().height : 0;

        page.style.setProperty('--fynn-frozen-top-h', `${Math.round(frozenHeight)}px`);

        const scrollRegion = scroller();
        const regionTop = scrollRegion ? scrollRegion.getBoundingClientRect().top : 0;
        const stickyLine = regionTop + headerHeight + frozenHeight;

        page.querySelectorAll('.fi-ta').forEach((table) => pinHeaderRow(table, stickyLine));
    }

    function schedule() {
        if (frame === null) {
            frame = window.requestAnimationFrame(update);
        }
    }

    function watchPage() {
        pageObserver?.disconnect();
        sizeObserver?.disconnect();

        const page = currentPage();

        if (! page) {
            return;
        }

        // Livewire re-renders replace the table (new <thead>, no transform):
        // re-pin after any DOM change on the page.
        pageObserver = new MutationObserver(schedule);
        pageObserver.observe(page, { childList: true, subtree: true });

        if ('ResizeObserver' in window) {
            sizeObserver = new ResizeObserver(schedule);
            const header = page.querySelector('.fi-page-header-main-ctn > .fi-header');

            if (header) {
                sizeObserver.observe(header);
            }

            page.querySelectorAll(`.fi-ta-header-ctn, .fi-ta-header-toolbar, ${FROZEN_TOP_SELECTOR}`).forEach((toolbar) => sizeObserver.observe(toolbar));
        }

        schedule();
    }

    function init() {
        window.addEventListener('scroll', schedule, true);
        window.addEventListener('resize', schedule);
        document.addEventListener('livewire:navigated', watchPage);
        watchPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
