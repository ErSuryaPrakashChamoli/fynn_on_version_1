{{--
    Demo mode only (see App\Providers\DemoModeServiceProvider): a floating
    pill in the bottom-right corner showing which persona is signed in,
    with a dropdown to jump to any other one. It floats rather than sitting
    in the topbar because the top-performer marquee is positioned against
    the topbar's fixed width, and anything added there pushes the month
    selector underneath it.

    The pill can be dragged anywhere on screen (mouse or touch) so it never
    covers what is being demoed; the spot is remembered per browser. A drag
    of more than a few pixels swallows the click so it doesn't open the menu.
--}}
<style>
    /*
     * The top-performer marquee's own animation starts the text a full
     * text-length off to the right, so after every page load the bar sits
     * blank for about a minute before the first name scrolls in. In a
     * client demo it has to show straight away: start at the left edge and
     * loop over the duplicated copy (the component renders the message
     * twice) so the scroll is continuous.
     */
    .fi-top-marquee-wrapper .marquee-text {
        animation: fynn-demo-marquee 90s linear infinite !important;
    }

    .fi-top-marquee-wrapper .marquee-text:hover {
        animation-play-state: paused !important;
    }

    @keyframes fynn-demo-marquee {
        from {
            transform: translateX(0);
        }

        to {
            transform: translateX(-50%);
        }
    }

    .fynn-demo-switcher {
        position: fixed;
        right: 1.25rem;
        bottom: 1.25rem;
        z-index: 40;
        touch-action: none;
        user-select: none;
    }

    .fynn-demo-switcher.is-dragging .fynn-demo-switcher-trigger {
        cursor: grabbing;
    }

    .fynn-demo-switcher-trigger {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.9rem;
        border-radius: 9999px;
        border: 1px solid rgb(255 255 255 / 18%);
        background: rgb(21 21 21);
        box-shadow: 0 8px 24px rgb(0 0 0 / 25%);
        font-size: 0.8125rem;
        font-weight: 600;
        color: rgb(255 255 255);
        white-space: nowrap;
        cursor: grab;
    }

    .fynn-demo-switcher-trigger:hover {
        background: rgb(38 38 43);
    }

    .fynn-demo-switcher-badge {
        padding: 0.05rem 0.4rem;
        border-radius: 9999px;
        background: var(--primary-400);
        font-size: 0.6875rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        color: rgb(21 21 21);
    }
</style>

<div
    class="fynn-demo-switcher"
    x-data="{
        storageKey: 'fynnon.demo-switcher-position',
        dragging: false,
        moved: false,
        startX: 0,
        startY: 0,
        offsetX: 0,
        offsetY: 0,
        init() {
            let saved = null;

            try {
                saved = JSON.parse(localStorage.getItem(this.storageKey));
            } catch (e) {}

            if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                this.$nextTick(() => this.place(saved.left, saved.top));
            }

            window.addEventListener('resize', () => {
                if (this.$el.style.left) {
                    this.place(parseFloat(this.$el.style.left), parseFloat(this.$el.style.top));
                }
            });
        },
        place(left, top) {
            const maxLeft = window.innerWidth - this.$el.offsetWidth - 8;
            const maxTop = window.innerHeight - this.$el.offsetHeight - 8;

            this.$el.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
            this.$el.style.top = Math.max(8, Math.min(top, maxTop)) + 'px';
            this.$el.style.right = 'auto';
            this.$el.style.bottom = 'auto';
        },
        start(event) {
            if (event.button !== 0) {
                return;
            }

            const rect = this.$el.getBoundingClientRect();

            this.dragging = true;
            this.moved = false;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.offsetX = event.clientX - rect.left;
            this.offsetY = event.clientY - rect.top;
        },
        move(event) {
            if (! this.dragging) {
                return;
            }

            if (! this.moved && Math.hypot(event.clientX - this.startX, event.clientY - this.startY) < 5) {
                return;
            }

            this.moved = true;
            this.place(event.clientX - this.offsetX, event.clientY - this.offsetY);
        },
        stop() {
            if (! this.dragging) {
                return;
            }

            this.dragging = false;

            if (this.moved) {
                try {
                    localStorage.setItem(this.storageKey, JSON.stringify({
                        left: parseFloat(this.$el.style.left),
                        top: parseFloat(this.$el.style.top),
                    }));
                } catch (e) {}
            }
        },
        swallowClickAfterDrag(event) {
            if (this.moved) {
                event.preventDefault();
                event.stopPropagation();
                this.moved = false;
            }
        },
    }"
    x-bind:class="{ 'is-dragging': dragging && moved }"
    x-on:pointerdown="start($event)"
    x-on:pointermove.window="move($event)"
    x-on:pointerup.window="stop()"
    x-on:pointercancel.window="stop()"
    x-on:click.capture="swallowClickAfterDrag($event)"
>
<x-filament::dropdown placement="top-end" width="xs" teleport>
    <x-slot name="trigger">
        <button type="button" class="fynn-demo-switcher-trigger" aria-label="Switch demo role">
            <span class="fynn-demo-switcher-badge">DEMO</span>
            <span>{{ $current ? $personas[$current]['label'] : 'Switch role' }}</span>
            <x-filament::icon icon="heroicon-m-chevron-up" class="h-4 w-4" />
        </button>
    </x-slot>

    <x-filament::dropdown.header icon="heroicon-o-arrows-right-left">
        View the demo as
    </x-filament::dropdown.header>

    <x-filament::dropdown.list>
        @foreach ($personas as $slug => $persona)
            <x-filament::dropdown.list.item
                tag="form"
                method="POST"
                :action="route('demo-persona.switch', $slug)"
                :icon="$slug === $current ? 'heroicon-m-check-circle' : 'heroicon-o-user-circle'"
                :color="$slug === $current ? 'primary' : 'gray'"
            >
                {{ $persona['label'] }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
</div>
