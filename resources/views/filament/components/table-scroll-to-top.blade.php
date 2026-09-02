{{--
    A "back to top" control at the very bottom of a listing page's table,
    below the pagination bar — for tables with enough rows to be worth
    scrolling for. Registered globally on
    PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, so it
    appears on every resource's List page automatically without any
    per-resource wiring.

    Scrolls .fi-main-ctn (the panel's single scroll region — see the
    GLOBAL APP SHELL rules in theme.css) directly back to its top.
--}}
<div
    x-data="{
        visible: false,
        check() {
            const scrollEl = document.querySelector('.fi-main-ctn')
            this.visible = !! (scrollEl && (scrollEl.scrollHeight - scrollEl.clientHeight) > 40)
        },
    }"
    x-init="
        check()
        window.addEventListener('resize', () => check())
        observer = new MutationObserver(() => check())
        observer.observe(document.querySelector('.fi-main-ctn') ?? $el, { childList: true, subtree: true })
    "
    x-show="visible"
    x-cloak
    class="fynn-table-scroll-to-top-ctn"
>
    <x-filament::button
        color="gray"
        outlined
        icon="heroicon-o-arrow-up"
        x-on:click="document.querySelector('.fi-main-ctn')?.scrollTo({ top: 0, behavior: 'smooth' })"
    >
        Back to top
    </x-filament::button>
</div>
