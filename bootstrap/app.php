<?php

use App\Http\Middleware\Portal\DetectDemoContext;
use App\Http\Middleware\Portal\RestrictPortalUsers;
use App\Http\Middleware\Portal\UseDemoContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Livewire's own update/polling route registers itself under
        // Laravel's default 'web' middleware group (see
        // Livewire\Mechanisms\HandleRequests\HandleRequests), which uses
        // the framework's stock EncryptCookies — not the admin panel's
        // custom subclass (App\Http\Middleware\EncryptCookies) that the
        // AdminPanelProvider's own middleware stack uses for full page
        // loads. Without this, every Livewire round-trip (widget polling,
        // filter changes, the calendar's own event fetch) decrypts these
        // plain client-set cookies, fails, and silently drops them —
        // making dashboard_theme/selected_month appear to "reset" on
        // anything but the very first page load. This registers them
        // globally so both middleware stacks skip encryption for them.
        $middleware->encryptCookies(except: [
            'dashboard_theme',
            'selected_month',
        ]);

        // Academy/Demo portal users must not be able to reach the plain
        // routes in routes/web.php (the OCR document download in
        // particular, which only asks for `auth`). The Filament panels
        // themselves are guarded separately by User::canAccessPanel();
        // this covers everything outside them. Users with no portal
        // account — the entire existing LMS population — pass through
        // untouched. See App\Http\Middleware\Portal\RestrictPortalUsers.
        $middleware->appendToGroup('web', RestrictPortalUsers::class);

        // /demo runs the admin application against the demo database (see
        // App\Support\Demo\DemoContext). The switch has to happen before
        // route-model binding and authentication resolve anything, so it
        // is placed ahead of both in the priority list. DetectDemoContext
        // does the same for Filament's panel-less export/import download
        // routes when they are called from /demo (?authGuard=demo).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: UseDemoContext::class,
        );
        $middleware->prependToGroup('web', DetectDemoContext::class);

        // A guest on one of the plain /demo routes is sent to the demo
        // login. Every other route keeps Laravel's default target.
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('demo', 'demo/*') ? '/demo/login' : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
