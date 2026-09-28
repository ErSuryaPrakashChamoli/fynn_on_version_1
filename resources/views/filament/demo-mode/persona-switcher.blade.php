{{--
    Demo mode only (see App\Providers\DemoModeServiceProvider): the persona
    modal, opened from the "View the demo as" entry under My Profile in the
    user menu (filament.demo-mode.user-menu-switcher). Rendered at BODY_END
    so it outlives the menu dropdown that opens it. It replaced a floating,
    draggable bottom-right pill on 2026-09-27.
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
</style>
<div class="fynn-demo-switcher">
    <x-filament::modal
        id="fynn-demo-switcher"
        width="sm"
        icon="heroicon-o-arrows-right-left"
        heading="View the demo as"
        :description="$current ? 'Currently '.$personas[$current]['label'] : null"
    >
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
    </x-filament::modal>
</div>
