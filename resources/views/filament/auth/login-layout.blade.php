@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $livewire ??= null;
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $settings = \App\Models\LoginPageSetting::current();

    // The classic placement (top, centered) reads as one branded unit with
    // the tagline right below it, so it's kept in the normal document flow
    // instead of floating -- a floating logo stays visually tied to the
    // panel's top edge while the centered content block below it drifts
    // independently, however tall the panel ends up being. Any other,
    // deliberately-chosen placement keeps floating, since an admin picking
    // e.g. "bottom right" clearly wants it decoupled from the tagline.
    $companyLogoIsDefaultInPanel = $settings->right_logo_side === 'right'
        && $settings->right_logo_vertical_align === 'top'
        && $settings->right_logo_horizontal_align === 'center';
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

        <div @class(['fynn-auth-banner', 'fynn-auth-banner--custom' => $settings->left_banner_url])>
            @if ($settings->left_banner_url)
                {{-- A complete, admin-uploaded banner -- shown exactly as
                     provided, in full: the column shrink-wraps to the
                     image's own aspect ratio instead of cropping or
                     letterboxing it into a fixed 50% box. --}}
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

            {{-- Shown over the custom banner image above if one is set, or
                 over the decorative background otherwise -- omitted
                 entirely (no default) when no logo has been uploaded, or
                 when its placement has been set to the right panel
                 instead, below. --}}
            @if ($settings->left_logo_side === 'left' && $settings->left_logo_url)
                <img
                    src="{{ $settings->left_logo_url }}"
                    alt=""
                    @class([
                        'fynn-auth-brand-logo',
                        'fynn-auth-logo-position',
                        'fynn-auth-logo-position--v-' . $settings->left_logo_vertical_align,
                        'fynn-auth-logo-position--h-' . $settings->left_logo_horizontal_align,
                    ])
                />
            @endif

            @unless ($settings->left_banner_url)
                <div class="fynn-auth-banner-content">
                    <p class="fynn-auth-banner-kicker">{{ $settings->left_tagline }}</p>

                    <h1 class="fynn-auth-banner-heading fynn-auth-banner-heading--{{ $settings->left_heading_size }} fynn-auth-text-{{ $settings->left_heading_align }}">
                        {{ $settings->left_heading }}
                    </h1>

                    <div @class(['fynn-auth-banner-accent-line', 'fynn-auth-banner-accent-line--' . $settings->left_heading_align])></div>
                </div>
            @endunless

            @if ($settings->right_logo_side === 'left')
                <img
                    src="{{ $settings->right_logo_url }}"
                    alt=""
                    @class([
                        'fynn-auth-company-logo',
                        'fynn-auth-logo-position',
                        'fynn-auth-logo-position--v-' . $settings->right_logo_vertical_align,
                        'fynn-auth-logo-position--h-' . $settings->right_logo_horizontal_align,
                    ])
                />
            @endif
        </div>

        <div class="fynn-auth-panel">
            @if ($settings->left_logo_side === 'right' && $settings->left_logo_url)
                <img
                    src="{{ $settings->left_logo_url }}"
                    alt=""
                    @class([
                        'fynn-auth-brand-logo',
                        'fynn-auth-logo-position',
                        'fynn-auth-logo-position--v-' . $settings->left_logo_vertical_align,
                        'fynn-auth-logo-position--h-' . $settings->left_logo_horizontal_align,
                    ])
                />
            @endif

            @if ($settings->right_logo_side === 'right' && ! $companyLogoIsDefaultInPanel)
                <img
                    src="{{ $settings->right_logo_url }}"
                    alt=""
                    @class([
                        'fynn-auth-company-logo',
                        'fynn-auth-logo-position',
                        'fynn-auth-logo-position--v-' . $settings->right_logo_vertical_align,
                        'fynn-auth-logo-position--h-' . $settings->right_logo_horizontal_align,
                    ])
                />
            @endif

            <div class="fynn-auth-panel-inner">
                @if ($companyLogoIsDefaultInPanel)
                    <img src="{{ $settings->right_logo_url }}" alt="" class="fynn-auth-company-logo fynn-auth-panel-logo-inline" />
                @endif

                <p class="fynn-auth-panel-tagline">{{ $settings->right_tagline }}</p>

                <svg class="fynn-auth-panel-divider" viewBox="0 0 300 20" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                    <polygon points="0,10 97,7.8 122,10 97,12.2" />
                    <circle cx="132" cy="10" r="2.6" />
                    <polygon points="150,3 157.5,10 150,17 142.5,10" />
                    <circle cx="168" cy="10" r="2.6" />
                    <polygon points="300,10 203,7.8 178,10 203,12.2" />
                </svg>

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
