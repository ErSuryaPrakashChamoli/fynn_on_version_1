{{--
    /demo only: the "Switch demo user" entry in the topbar user menu,
    rendered on PanelsRenderHook::USER_MENU_PROFILE_AFTER so it sits right
    under "My Profile". It only opens the modal in
    filament.demo.role-switcher (rendered at BODY_END, outside the menu's
    own dropdown so it survives the menu closing).
--}}
@php
    $user = filament()->auth()->user();
    $currentRole = $user?->roles->pluck('name')->first() ?? 'Demo user';
@endphp

<x-filament::dropdown.list.item
    icon="heroicon-m-arrows-right-left"
    color="warning"
    :badge="$currentRole"
    badge-color="warning"
    x-on:click="$dispatch('open-modal', { id: 'demo-view-as' })"
    title="Demo environment — switch user"
    class="demo-view-as-menu-item"
>
    Switch demo user
</x-filament::dropdown.list.item>
