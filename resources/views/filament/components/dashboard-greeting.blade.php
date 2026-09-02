{{--
    Greeting banner rendered on PanelsRenderHook::PAGE_START, scoped to
    App\Filament\Pages\Dashboard only (see AdminPanelProvider::panel()) — so
    it sits just above the "Dashboard" page heading, for every signed-in
    user regardless of role. The name always reflects whoever is currently
    authenticated (the name an admin set on their user record), the same
    resolver the user menu label already uses. The tagline is editable by
    an Admin at any time via App\Filament\Pages\DashboardGreetingSettings
    (App\Models\DashboardGreetingSetting), so both the tagline and the icon
    can be refreshed with a new thought daily without a deploy. The icon is
    either a "heroicon-o-{slug}" string or a plain emoji character -- see
    DashboardGreetingSettings::iconOptions() for the selectable set.
--}}
@php
    $hour = now()->hour;
    $timeGreeting = match (true) {
        $hour < 12 => 'Good Morning',
        $hour < 17 => 'Good Afternoon',
        default => 'Good Evening',
    };
    $userName = \Filament\Facades\Filament::getUserName(\Filament\Facades\Filament::auth()->user());
    $settings = \App\Models\DashboardGreetingSetting::current();
    $isHeroicon = str_starts_with($settings->icon, 'heroicon-');
@endphp

<div class="fynn-dashboard-greeting">
    <p class="fynn-dashboard-greeting-text">
        <span class="fynn-dashboard-greeting-headline">{{ $timeGreeting }}, {{ $userName }}!</span>
        <span class="fynn-dashboard-greeting-dash"></span>
        <span class="fynn-dashboard-greeting-sub-line">
            <span class="fynn-dashboard-greeting-sub">{{ $settings->tagline }}</span>

            @if ($isHeroicon)
                <x-filament::icon
                    :icon="$settings->icon"
                    class="fynn-dashboard-greeting-icon"
                />
            @else
                <span class="fynn-dashboard-greeting-icon fynn-dashboard-greeting-icon--emoji">{{ $settings->icon }}</span>
            @endif
        </span>
    </p>
</div>
