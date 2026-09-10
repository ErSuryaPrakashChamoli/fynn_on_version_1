<?php

namespace App\Providers\Filament;

use App\Filament\Demo\Pages\Auth\DemoLogin;
use App\Filament\Demo\Pages\DemoDashboard;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\Portal\EnsureDemoAccess;
use App\Support\Portal\FynnOnBrand;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * FYNN-ON Demo — the external sandbox shown to prospects.
 *
 * Presents FYNN-ON as an independent SaaS product: no FynnEdge company
 * branding, no internal terminology, no link back to /admin. Every
 * resource in it is bound to a demo_* model, so the panel has no
 * reachable path to a production row (see the demo_banks migration for
 * why isolation is schema-level rather than query-level).
 *
 * Mounted at /demo, moved to demo.fynnedge.com later by adding
 * ->domain() here alone.
 */
class DemoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('demo')
            ->path('demo')
            ->login(DemoLogin::class)
            ->brandName('FYNN-ON')
            ->favicon(asset('images/favicon.png'))
            ->colors(FynnOnBrand::colors())
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->globalSearch(false)
            ->discoverResources(
                in: app_path('Filament/Demo/Resources'),
                for: 'App\Filament\Demo\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Demo/Pages'),
                for: 'App\Filament\Demo\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Demo/Widgets'),
                for: 'App\Filament\Demo\Widgets',
            )
            ->pages([
                DemoDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Organisation'),
                NavigationGroup::make('Configuration'),
            ])
            /*
             * A persistent "sandbox data" banner. A prospect must never
             * be in any doubt that the numbers in front of them are
             * fabricated, and a render hook puts it on every page
             * without each page having to remember.
             */
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.demo.sandbox-badge')->render(),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.demo.styles')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureAccountIsActive::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureDemoAccess::class,
            ]);
    }
}
