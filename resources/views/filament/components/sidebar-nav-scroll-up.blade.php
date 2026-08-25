<div
    x-data="{
        navEl: null,
        visible: false,
        init() {
            this.navEl = this.$el.closest('.fi-sidebar-nav')

            if (! this.navEl) {
                return
            }

            this.update()
            this.navEl.addEventListener('scroll', () => this.update(), { passive: true })
            this.observer = new ResizeObserver(() => this.update())
            this.observer.observe(this.navEl)
        },
        update() {
            this.visible = this.navEl.scrollTop > 4
        },
        scrollNav() {
            this.navEl.scrollTo({ top: 0, behavior: 'smooth' })
        },
    }"
    x-show="visible"
    x-transition.opacity.duration.150ms
    x-cloak
    class="fynn-sidebar-nav-scroll fynn-sidebar-nav-scroll-up"
>
    <x-filament::icon-button
        icon="heroicon-o-chevron-up"
        label="Scroll to top of navigation"
        tooltip="Scroll to top of navigation"
        color="gray"
        x-on:click="scrollNav()"
        class="fynn-sidebar-nav-scroll-btn"
    />
</div>
