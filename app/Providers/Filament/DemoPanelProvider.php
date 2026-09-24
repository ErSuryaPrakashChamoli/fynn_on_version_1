<?php

namespace App\Providers\Filament;

use App\Filament\Demo\Pages\Auth\DemoLogin;
use App\Filament\Demo\Pages\DemoDashboard;
use App\Http\Middleware\Portal\EnsureDemoAccess;
use App\Http\Middleware\Portal\EnsureDemoIsAvailable;
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
 * branding, no internal terminology, no link back to /admin.
 *
 * Isolated from /admin at every layer:
 *  - data: every resource is bound to an App\Models\Demo model, all of
 *    which live on the separate `demo` database connection;
 *  - identity: the panel authenticates on the `demo` guard against
 *    demo_users in that database, so a main LMS session is a guest here
 *    and a demo session is a guest on /admin;
 *  - configuration: EnsureDemoIsAvailable refuses every request if the
 *    demo connection resolves to the main database (or the panel is
 *    switched off with DEMO_PANEL_ENABLED=false).
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
            ->authGuard('demo')
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
            /*
             * The login-session heartbeat script is registered globally,
             * but a DemoUser has no main-database login session to beat
             * against. This tag makes the script stay dormant here.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="login-session-heartbeat" content="off">',
            )
            /*
             * Persistent: re-applied by Livewire on every component
             * round-trip too, not only on full page loads.
             */
            ->middleware([
                EnsureDemoIsAvailable::class,
            ], isPersistent: true)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
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
            ], isPersistent: true);
    }
}
