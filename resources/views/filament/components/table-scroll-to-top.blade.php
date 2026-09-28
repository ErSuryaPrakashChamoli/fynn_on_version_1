{{--
    Floating scroll arrows for listing pages. Registered globally on
    PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, so they
    appear on every resource's List page automatically without any
    per-resource wiring, and on nothing else.

    On this theme the table body is its own bounded scroll box
    (.fi-ta-content-ctn, max-height 65dvh — see STICKY TABLE HEADER in
    theme.css), so that is what usually scrolls, not the page. The arrows
    therefore pick whichever box actually overflows — the table box first,
    then .fi-main-ctn (the panel's scroll region), then the document — and
    pin themselves to the bottom-right corner of that box. The up arrow
    shows once it is scrolled down; the down arrow while there is more.
    position: fixed, because the overflow clipping around the table
    silently disables position: sticky.
--}}
<div
    x-data="{
        canGoUp: false,
        canGoDown: false,
        target: null,
        findTarget() {
            const page = $el.closest('.fi-page') ?? document
            const candidates = [
                page.querySelector('.fi-ta-content-ctn'),
                document.querySelector('.fi-body-has-navigation .fi-main-ctn'),
                document.scrollingElement,
            ]
            return candidates.find(el => el && el.scrollHeight - el.clientHeight > 40) ?? null
        },
        check() {
            this.target = this.findTarget()
            const el = this.target
            if (! el) { this.canGoUp = this.canGoDown = false; return }
            const remaining = el.scrollHeight - el.clientHeight - el.scrollTop
            this.canGoUp = el.scrollTop > 80
            this.canGoDown = remaining > 80
            this.place(el)
        },
        place(el) {
            // Anchor to the pagination bar under the table box, which has
            // nothing clickable on its right — over the table itself the
            // arrows would cover the last rows' action buttons.
            const page = $el.closest('.fi-page') ?? document
            const footer = el.classList?.contains('fi-ta-content-ctn')
                ? [...page.querySelectorAll('.fi-ta .fi-pagination')].pop() ?? null
                : null
            const anchor = footer?.getBoundingClientRect() ?? (el === document.scrollingElement ? null : el.getBoundingClientRect())
            const right = anchor ? Math.max(16, window.innerWidth - anchor.right + 12) : 24
            const bottom = anchor ? Math.max(16, window.innerHeight - anchor.bottom + 10) : 24
            $el.style.right = right + 'px'
            $el.style.bottom = bottom + 'px'
        },
        go(toBottom) {
            const el = this.target ?? this.findTarget()
            el?.scrollTo({ top: toBottom ? el.scrollHeight : 0, behavior: 'smooth' })
        },
    }"
    x-init="
        check()
        window.addEventListener('scroll', () => check(), true)
        window.addEventListener('resize', () => check())
        new MutationObserver(() => check()).observe(document.body, { childList: true, subtree: true })
    "
    class="fynn-table-scroll-to-top-ctn"
>
    <button
        type="button"
        class="fynn-table-scroll-to-top"
        x-show="canGoUp"
        x-transition.opacity.duration.150ms
        x-cloak
        title="Scroll to top"
        aria-label="Scroll to top"
        x-on:click="go(false)"
    >
        <x-filament::icon icon="heroicon-m-arrow-up" class="fynn-table-scroll-to-top-icon" />
    </button>

    <button
        type="button"
        class="fynn-table-scroll-to-top fynn-table-scroll-to-bottom"
        x-show="canGoDown"
        x-transition.opacity.duration.150ms
        x-cloak
        title="Scroll to bottom"
        aria-label="Scroll to bottom"
        x-on:click="go(true)"
    >
        <x-filament::icon icon="heroicon-m-arrow-down" class="fynn-table-scroll-to-top-icon" />
    </button>
</div>
