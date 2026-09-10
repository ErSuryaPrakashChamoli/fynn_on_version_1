<?php

namespace App\Providers\Filament;

use App\Filament\Academy\Pages\AcademyDashboard;
use App\Filament\Academy\Pages\Auth\AcademyLogin;
use App\Http\Controllers\Academy\TrainingDocumentDownloadController;
use App\Http\Middleware\Portal\EnsureAcademyAccess;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * FYNN-ON Academy — the internal training portal.
 *
 * Mounted at /academy so it can be moved to academy.fynnedge.com by
 * adding ->domain(config('portal.academy_domain')) and nothing else: no
 * route, redirect or asset path in this panel is written with the /admin
 * host baked in, and every URL is generated through Filament's own
 * resource/page helpers.
 *
 * Three things keep this panel away from the LMS:
 *
 *  1. It discovers ONLY app/Filament/Academy/** — the admin panel's
 *     discoverResources() call points at app/Filament/Resources, a
 *     sibling directory, so neither panel can pick up the other's
 *     classes even by accident.
 *  2. User::canAccessPanel() refuses anyone whose portal account names a
 *     different panel — including on Livewire round-trips, via
 *     Filament's persistent Authenticate middleware.
 *  3. EnsureAcademyAccess re-checks the same thing independently in the
 *     auth middleware stack.
 */
class AcademyPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('academy')
            ->path('academy')
            ->login(AcademyLogin::class)
            ->brandName('FYNN-ON Academy')
            ->favicon(asset('images/favicon.png'))
            ->colors(FynnOnBrand::colors())
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            // The Academy has no global search index of its own, and
            // leaving Filament's default on would let a trainee type a
            // production customer name into a box that at least LOOKS
            // like it might answer.
            ->globalSearch(false)
            ->discoverResources(
                in: app_path('Filament/Academy/Resources'),
                for: 'App\Filament\Academy\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Academy/Pages'),
                for: 'App\Filament\Academy\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Academy/Widgets'),
                for: 'App\Filament\Academy\Widgets',
            )
            ->pages([
                AcademyDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Learning'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('Delivery'),
                NavigationGroup::make('Assessment'),
            ])
            /*
             * Authorised document downloads. Registered on the panel
             * (rather than in routes/web.php) so the route inherits the
             * panel's full middleware stack — session, auth,
             * canAccessPanel and EnsureAcademyAccess — before the
             * controller's own enrollment check even runs.
             */
            ->routes(function (): void {
                Route::get(
                    'training/documents/{document}',
                    TrainingDocumentDownloadController::class,
                )->name('training.documents.download');
            })
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.academy.styles')->render(),
            )
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
                EnsureAcademyAccess::class,
            ]);
    }
}
