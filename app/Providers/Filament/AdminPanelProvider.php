<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// use App\Filament\Widgets\AchievementChart;
use App\Filament\Widgets\DailyCommitmentStats;
use App\Filament\Widgets\TargetStats;
use App\Filament\Widgets\IncentiveStats;
use App\Filament\Widgets\PerformanceStats;

use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use App\Filament\Widgets\ManagerPPPStats;
use App\Filament\Pages\ChangePassword;


class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandLogo(asset('images/fynnedge.jpeg'))
            ->brandLogoHeight('8.5rem')
            ->sidebarFullyCollapsibleOnDesktop()
            ->globalSearch(false)
            ->brandName('Finn On')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->renderHook(
                // PanelsRenderHook::TOPBAR_START,
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                function () {
                    return Blade::render('@livewire("top-performer-marquee")');
                }
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                ChangePassword::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // TargetStats::class,
                DailyCommitmentStats::class,
                IncentiveStats::class,
                PerformanceStats::class,
                ManagerPPPStats::class,



            ])
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
            ]);
    }
}
