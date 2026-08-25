{{--
    Override of vendor/filament/filament/resources/views/components/theme-switcher/index.blade.php.

    Also drops the "system" option and the plain "light" button — the
    latter was a duplicate of the Indigo + Teal swatch below: both did
    exactly the same thing (opt out to the classic cookie, set light
    mode), just one had a colored identity and one didn't. What's left is
    Moon (Filament's normal dark-mode toggle — see below) plus three
    explicit theme-color buttons: "Indigo + Teal" (this panel's original
    look), "Emerald + Charcoal", and "FYNN-ON" (the brand palette — the
    current default). Each renders a small gradient dot
    (.fynn-theme-swatch in theme.css) in that theme's actual colors
    instead of a generic icon, so the option is identifiable at a glance
    rather than only tinted by whichever theme currently happens to be
    active. All three are read server-side in AdminPanelProvider
    (activeTheme()/buildColors()) to swap the panel's ->colors() via a
    `dashboard_theme` cookie — `classic` and `emerald` select those two
    themes, anything else (including no cookie) means FYNN-ON. Because
    colors are baked into the page at render time, switching requires a
    reload. Only Indigo + Teal has a dark variant — Emerald and FYNN-ON
    both force light mode — so clicking Moon while either is active opts
    out to Indigo + Teal dark instead of changing their content
    background.
--}}
@php
    $dashboardTheme = request()->cookie('dashboard_theme');
@endphp
<div
    x-data="{ theme: null, initialized: false }"
    x-init="
        $watch('theme', () => {
            $dispatch('theme-changed', theme)

            // $watch also fires for the initial sync below (theme going
            // from null to the stored value on every page load), not
            // just for an actual click — without this guard, loading a
            // page that's already opted out to classic would immediately
            // re-trigger this and reload again. $nextTick defers the
            // flag past that first, synchronous sync-triggered call.
            if (initialized && (! document.cookie.includes('dashboard_theme=classic'))) {
                document.cookie = 'dashboard_theme=classic; path=/; max-age=31536000; samesite=lax'
                window.location.reload()
            }
        })

        theme = localStorage.getItem('theme') || @js(filament()->getDefaultThemeMode()->value)

        $nextTick(() => { initialized = true })
    "
    role="group"
    aria-label="{{ __('filament-panels::layout.actions.theme_switcher.label') }}"
    class="fi-theme-switcher"
>
    <x-filament-panels::theme-switcher.button
        :icon="\Filament\Support\Icons\Heroicon::Moon"
        theme="dark"
    />

    <button
        aria-label="Indigo + Teal theme"
        type="button"
        x-on:click="
            localStorage.setItem('theme', 'light')
            document.cookie = 'dashboard_theme=classic; path=/; max-age=31536000; samesite=lax'
            window.location.reload()
        "
        x-tooltip="{
            content: @js('Indigo + Teal theme'),
            theme: $store.theme,
        }"
        aria-pressed="{{ $dashboardTheme === 'classic' ? 'true' : 'false' }}"
        @class([
            'fi-theme-switcher-btn',
            'fi-active' => $dashboardTheme === 'classic',
        ])
    >
        <span class="fynn-theme-swatch fynn-theme-swatch--classic"></span>
    </button>

    <button
        aria-label="Emerald + Charcoal theme"
        type="button"
        x-on:click="
            localStorage.setItem('theme', 'light')
            document.cookie = 'dashboard_theme=emerald; path=/; max-age=31536000; samesite=lax'
            window.location.reload()
        "
        x-tooltip="{
            content: @js('Emerald + Charcoal theme'),
            theme: $store.theme,
        }"
        aria-pressed="{{ $dashboardTheme === 'emerald' ? 'true' : 'false' }}"
        @class([
            'fi-theme-switcher-btn',
            'fi-active' => $dashboardTheme === 'emerald',
        ])
    >
        <span class="fynn-theme-swatch fynn-theme-swatch--emerald"></span>
    </button>

    <button
        aria-label="FYNN-ON theme"
        type="button"
        x-on:click="
            localStorage.setItem('theme', 'light')
            document.cookie = 'dashboard_theme=; path=/; max-age=0'
            window.location.reload()
        "
        x-tooltip="{
            content: @js('FYNN-ON theme'),
            theme: $store.theme,
        }"
        aria-pressed="{{ ! in_array($dashboardTheme, ['classic', 'emerald'], true) ? 'true' : 'false' }}"
        @class([
            'fi-theme-switcher-btn',
            'fi-active' => ! in_array($dashboardTheme, ['classic', 'emerald'], true),
        ])
    >
        <span class="fynn-theme-swatch fynn-theme-swatch--fynnon"></span>
    </button>
</div>
