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
    @include('filament.demo.partials.draggable', ['storageKey' => 'fynnon.demo-switcher-position'])
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
