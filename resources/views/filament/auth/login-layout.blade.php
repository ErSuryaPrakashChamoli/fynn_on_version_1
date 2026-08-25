@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $livewire ??= null;
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $settings = \App\Models\LoginPageSetting::current();
@endphp

{{--
    A from-scratch replacement for `filament-panels::components.layout.simple`
    (the vendor login layout), used only by App\Filament\Pages\Auth\Login.
    Still wraps `layout.base` for the full `<html>`/asset pipeline/dark-mode
    bootstrapping/notifications every panel page needs -- only the inner
    centered-card chrome is replaced with a full-bleed two-column banner.
    `{{ $slot }}` is the login page's own `page.simple` output (the real
    Filament-rendered form, untouched), it's just placed inside the right
    column here instead of a centered card.
--}}
<x-filament-panels::layout.base :livewire="$livewire">
    <div id="fi-main-content" class="fynn-auth">
        {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, scopes: $renderHookScopes) }}

        <div class="fynn-auth-banner">
            @if ($settings->left_banner_url)
                {{-- A complete, admin-uploaded banner -- shown exactly as
                     provided, filling the panel (cropped, not stretched). --}}
                <img src="{{ $settings->left_banner_url }}" alt="" class="fynn-auth-banner-image" />
            @else
                <div class="fynn-auth-banner-glow fynn-auth-banner-glow-a"></div>
                <div class="fynn-auth-banner-glow fynn-auth-banner-glow-b"></div>
                <div class="fynn-auth-banner-dots"></div>

                <svg class="fynn-auth-banner-wave" viewBox="0 0 600 220" preserveAspectRatio="none" fill="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="fynnWaveGradient" x1="0" y1="0" x2="600" y2="0" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#a3e635" stop-opacity="0" />
                            <stop offset="45%" stop-color="#a3e635" stop-opacity="0.9" />
                            <stop offset="100%" stop-color="#a3e635" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <path d="M0 170 C 90 120, 150 200, 240 150 S 420 60, 600 130" stroke="url(#fynnWaveGradient)" stroke-width="2" />
                    <path d="M0 190 C 100 150, 180 210, 270 175 S 450 100, 600 165" stroke="url(#fynnWaveGradient)" stroke-width="1" opacity="0.5" />
                </svg>
            @endif

            {{-- The logo is always shown, over the custom banner image
                 above if one is set, or over the decorative background
                 otherwise. --}}
            <div class="fynn-auth-banner-logo-overlay">
                <img src="{{ $settings->left_logo_url }}" alt="" class="fynn-auth-banner-logo" />
            </div>

            @unless ($settings->left_banner_url)
                <div class="fynn-auth-banner-content">
                    <p class="fynn-auth-banner-kicker">{{ $settings->left_tagline }}</p>

                    <h1 class="fynn-auth-banner-heading fynn-auth-banner-heading--{{ $settings->left_heading_size }} fynn-auth-text-{{ $settings->left_heading_align }}">
                        {{ $settings->left_heading }}
                    </h1>

                    <div @class(['fynn-auth-banner-accent-line', 'fynn-auth-banner-accent-line--' . $settings->left_heading_align])></div>
                </div>
            @endunless
        </div>

        <div class="fynn-auth-panel">
            <div class="fynn-auth-panel-inner">
                <img src="{{ $settings->right_logo_url }}" alt="" class="fynn-auth-panel-logo" />

                <p class="fynn-auth-panel-tagline">{{ $settings->right_tagline }}</p>

                <div class="fynn-auth-panel-divider"></div>

                <h2 class="fynn-auth-panel-heading fynn-auth-panel-heading--{{ $settings->welcome_heading_size }} fynn-auth-text-{{ $settings->welcome_heading_align }}">
                    {{ $settings->welcome_heading }}
                </h2>
                <p class="fynn-auth-panel-subheading fynn-auth-text-{{ $settings->welcome_heading_align }}">
                    {!! str($settings->welcome_subheading)->replace('Fynn-ON LMS', '<span class="fynn-auth-panel-subheading-accent">Fynn-ON LMS</span>') !!}
                </p>

                {{ $slot }}

                <p class="fynn-auth-panel-footer">{{ $settings->footer_text }}</p>
            </div>
        </div>

        {{ FilamentView::renderHook(PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
        {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_LAYOUT_END, scopes: $renderHookScopes) }}
    </div>
</x-filament-panels::layout.base>
