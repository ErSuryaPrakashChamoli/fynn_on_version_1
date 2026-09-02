(() => {
    'use strict';

    if (window.__fynnTableScrollNavInitialized) {
        return;
    }

    window.__fynnTableScrollNavInitialized = true;

    // Each "step" click moves ~72% of the visible width -- enough to make
    // real progress across a wide table without ever jumping straight to
    // the opposite extreme.
    const SCROLL_RATIO = 0.72;

    // A cluster only renders at the top/bottom (in addition to the middle)
    // once the listing's own full height clears this -- short tables just
    // get the single centred cluster.
    const TALL_LISTING_HEIGHT = 480;

    const POSITIONS = ['top', 'middle', 'bottom'];

    // Built from the same chevron glyph used for the primary "step" arrows,
    // duplicated and offset to read as a double-chevron "jump to edge" icon.
    function chevronPath(direction) {
        return direction === 'left'
            ? 'M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z'
            : 'M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z';
    }

    function stepIcon(direction) {
        return `<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="${chevronPath(direction)}" clip-rule="evenodd" /></svg>`;
    }

    function jumpIcon(direction) {
        const dx = direction === 'left' ? -3 : 3;
        const path = chevronPath(direction);

        return `<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">`
            + `<path fill-rule="evenodd" transform="translate(${-dx}, 0)" d="${path}" clip-rule="evenodd" />`
            + `<path fill-rule="evenodd" transform="translate(${dx}, 0)" d="${path}" clip-rule="evenodd" />`
            + `</svg>`;
    }

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function scrollStep(container, direction) {
        const amount = Math.round(container.clientWidth * SCROLL_RATIO) * (direction === 'left' ? -1 : 1);

        container.scrollBy({
            left: amount,
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }

    function scrollToEdge(container, direction) {
        const left = direction === 'left' ? 0 : container.scrollWidth - container.clientWidth;

        container.scrollTo({
            left,
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }

    function createButton({ className, label, icon, onClick }) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.innerHTML = icon;

        // Scrolling must never bubble into row single/double-click handling
        // (table-row-actions.js) or any other document-level listener.
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            onClick();
        });

        return button;
    }

    function createCluster(container, side, position) {
        const nav = document.createElement('div');
        nav.className = `fynn-table-scroll-nav fynn-table-scroll-nav--${side}`;
        nav.dataset.fynnSide = side;
        nav.dataset.fynnPos = position;

        const stack = document.createElement('div');
        stack.className = 'fynn-table-scroll-stack';

        const stepLabel = side === 'left' ? 'Scroll table left' : 'Scroll table right';
        const jumpLabel = side === 'left' ? 'Scroll table to the start' : 'Scroll table to the end';

        stack.appendChild(createButton({
            className: 'fynn-table-scroll-arrow-btn',
            label: stepLabel,
            icon: stepIcon(side),
            onClick: () => scrollStep(container, side),
        }));

        stack.appendChild(createButton({
            className: 'fynn-table-scroll-arrow-btn fynn-table-scroll-arrow-btn--jump',
            label: jumpLabel,
            icon: jumpIcon(side),
            onClick: () => scrollToEdge(container, side),
        }));

        nav.appendChild(stack);

        return nav;
    }

    // An absolutely positioned descendant of `container` (the scrolling
    // element itself) is still part of what gets scrolled -- its rendered
    // position drifts left by exactly `container.scrollLeft`, same as any
    // row would (verified by measuring a cluster's on-screen position
    // before/after a scroll: it moved by precisely the scroll delta).
    // `position: sticky` was also tried and measured, but a `right: 0`
    // sticky element's un-stuck static position is always flush with its
    // containing block's LEFT edge regardless of the `right` offset, so it
    // never actually reaches a stuck state and drifts exactly like `absolute`
    // did (`left: 0` sticky drifted too, once the wrapper wasn't naturally
    // positioned at the trailing edge -- this is a `right`-specific
    // asymmetry, not a general sticky failure). Cancelling the drift with an
    // explicit counter-transform is simple, symmetric for both sides, and
    // avoids relying on sticky's containing-block quirks entirely.
    //
    // `container` (.fi-ta-content-ctn) is now also bounded to a max-height
    // with its own vertical overflow (see the STICKY TABLE HEADER CSS), so
    // it scrolls on both axes -- these clusters drift on a row scroll the
    // same way they drift on a column scroll, and the `translateY` term
    // below cancels that on top of its own `-50%` self-centering.
    function updateVisibility(container) {
        const clusters = container.querySelectorAll(':scope > .fynn-table-scroll-nav');

        if (!clusters.length) {
            return;
        }

        const maxScrollLeft = container.scrollWidth - container.clientWidth;
        const hasOverflow = maxScrollLeft > 2;
        const scrollLeft = container.scrollLeft;
        const scrollTop = container.scrollTop;
        const canScrollLeft = hasOverflow && scrollLeft > 2;
        const canScrollRight = hasOverflow && scrollLeft < maxScrollLeft - 2;
        const stepAmount = container.clientWidth * SCROLL_RATIO;
        const farFromStart = scrollLeft > stepAmount * 1.4;
        const farFromEnd = (maxScrollLeft - scrollLeft) > stepAmount * 1.4;
        const isTall = container.offsetHeight > TALL_LISTING_HEIGHT;
        const compensation = `translate(${scrollLeft}px, calc(-50% + ${scrollTop}px))`;

        clusters.forEach((cluster) => {
            const isLeft = cluster.dataset.fynnSide === 'left';
            const isMiddle = cluster.dataset.fynnPos === 'middle';
            const baseVisible = isLeft ? canScrollLeft : canScrollRight;
            const visible = baseVisible && (isMiddle || isTall);

            cluster.classList.toggle('is-visible', visible);
            cluster.style.transform = compensation;

            const jumpBtn = cluster.querySelector('.fynn-table-scroll-arrow-btn--jump');

            if (jumpBtn) {
                jumpBtn.classList.toggle('is-visible', visible && (isLeft ? farFromStart : farFromEnd));
            }
        });
    }

    const pendingVisibilityUpdates = new WeakSet();

    function scheduleVisibilityUpdate(container) {
        if (pendingVisibilityUpdates.has(container)) {
            return;
        }

        pendingVisibilityUpdates.add(container);

        requestAnimationFrame(() => {
            pendingVisibilityUpdates.delete(container);
            updateVisibility(container);
        });
    }

    // Livewire re-renders a table by diffing server HTML against the live
    // DOM, which has no knowledge of these client-injected clusters -- a
    // re-render (sort, filter, paginate) can evict them. Re-append whenever
    // they go missing from an already-initialised container.
    function ensureNavElements(container) {
        ['left', 'right'].forEach((side) => {
            POSITIONS.forEach((position) => {
                const selector = `:scope > .fynn-table-scroll-nav--${side}[data-fynn-pos="${position}"]`;

                if (!container.querySelector(selector)) {
                    container.appendChild(createCluster(container, side, position));
                }
            });
        });
    }

    function setupContainer(container) {
        if (!container.hasAttribute('data-fynn-scroll-nav-ready')) {
            container.setAttribute('data-fynn-scroll-nav-ready', '1');

            container.addEventListener('scroll', () => scheduleVisibilityUpdate(container), { passive: true });

            const resizeObserver = new ResizeObserver(() => scheduleVisibilityUpdate(container));
            resizeObserver.observe(container);

            // The container's own box rarely changes size on content growth
            // (it's constrained by the page layout) -- what actually
            // determines overflow, and the listing's total height used for
            // the top/middle/bottom "tall listing" check, is the size of the
            // table/content inside it, so that also needs to be watched.
            const innerContent = container.querySelector(':scope > .fi-ta-content') ?? container.querySelector('table');

            if (innerContent) {
                resizeObserver.observe(innerContent);
            }

            const healingObserver = new MutationObserver(() => {
                ensureNavElements(container);
                scheduleVisibilityUpdate(container);
            });

            healingObserver.observe(container, { childList: true });
        }

        ensureNavElements(container);
        scheduleVisibilityUpdate(container);
    }

    function isTableScrollContainer(el) {
        if (el.classList.contains('fi-ta-content-ctn')) {
            return true;
        }

        return el.classList.contains('overflow-x-auto') && el.querySelector('table') !== null;
    }

    // `#fi-main-content` -- not document.body -- is the element that survives
    // Filament's `wire:navigate` SPA transitions (see resources/js/page-
    // transitions.js's own comment on this); body itself gets replaced with a
    // new element on navigation, which silently orphans any observer bound to
    // it. Falls back to body for the rare page that lacks the id (e.g. login).
    function getScanRoot() {
        return document.getElementById('fi-main-content') ?? document.body;
    }

    function scan(root) {
        root.querySelectorAll('.fi-ta-content-ctn, .overflow-x-auto').forEach((el) => {
            if (isTableScrollContainer(el)) {
                setupContainer(el);
            }
        });
    }

    let scanScheduled = false;

    function scheduleScan() {
        if (scanScheduled) {
            return;
        }

        scanScheduled = true;

        requestAnimationFrame(() => {
            scanScheduled = false;
            scan(getScanRoot());
        });
    }

    let bodyObserver = null;

    // Re-runs on every SPA navigation (not just the first): `#fi-main-content`
    // is a fresh call each time via getScanRoot(), and even if some future
    // Filament change makes *that* element get replaced too, re-attaching the
    // observer here rather than assuming it survives forever keeps this
    // self-healing instead of silently going dead after one navigation (which
    // is exactly the bug this replaced -- confirmed by tagging document.body
    // and watching the tag vanish after a single wire:navigate hop).
    function attachObserver() {
        const root = getScanRoot();

        bodyObserver?.disconnect();

        bodyObserver = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length) {
                    scheduleScan();

                    return;
                }
            }
        });

        bodyObserver.observe(root, { childList: true, subtree: true });
    }

    function onNavigate() {
        attachObserver();
        scan(getScanRoot());
    }

    function init() {
        onNavigate();

        document.addEventListener('livewire:navigated', onNavigate);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
