<?php

namespace App\Providers;

use App\Http\Controllers\DemoPersonaSwitchController;
use App\Support\DemoPersonas;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Turns the ordinary /admin panel into the client demo when DEMO_MODE is
 * on: a persona picker under the login form and a floating "viewing as"
 * switcher. With DEMO_MODE off (every real deployment) this
 * provider registers nothing at all, so the live panel is untouched.
 */
class DemoModeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! DemoPersonas::enabled()) {
            return;
        }

        Route::middleware(['web', 'throttle:30,1'])
            ->post('/demo-persona/{persona}', DemoPersonaSwitchController::class)
            ->name('demo-persona.switch');

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            fn (): string => $this->isAdminPanel()
                ? view('filament.demo-mode.login-personas', ['personas' => DemoPersonas::all()])->render()
                : '',
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => $this->isAdminPanel() && Filament::auth()->check()
                ? view('filament.demo-mode.persona-switcher', [
                    'personas' => DemoPersonas::all(),
                    'current' => DemoPersonas::slugFor(Filament::auth()->user()),
                ])->render()
                : '',
        );
    }

    private function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }
}
