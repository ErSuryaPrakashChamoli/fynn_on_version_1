{{--
    Demo mode only (see App\Providers\DemoModeServiceProvider): the "View
    the demo as" entry under My Profile in the user menu. It opens the
    persona modal in filament.demo-mode.persona-switcher.
--}}
<x-filament::dropdown.list.item
    icon="heroicon-o-arrows-right-left"
    color="primary"
    :badge="$current ? $personas[$current]['label'] : 'Demo'"
    x-on:click="$dispatch('open-modal', { id: 'fynn-demo-switcher' })"
    class="fynn-demo-switcher-menu-item"
>
    View the demo as
</x-filament::dropdown.list.item>
