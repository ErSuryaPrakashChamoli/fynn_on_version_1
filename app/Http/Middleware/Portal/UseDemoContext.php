<?php

namespace App\Http\Middleware\Portal;

use App\Support\Demo\DemoContext;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a /demo request into the demo environment.
 *
 * Registered first (and persistent, so Livewire round-trips get it too)
 * on the demo panel and on the handful of plain /demo routes. From here
 * on the SAME admin models, services and jobs read and write the DEMO
 * database, queue, cache and storage — see DemoContext.
 *
 * The default auth guard is switched to `demo` as well, so anything that
 * asks for "the current user" before Filament's own Authenticate runs
 * (EnsureAccountIsActive, the login-session listeners) sees the demo
 * login, never a main-database user id resolved against the demo users
 * table.
 *
 * The context (and the default guard) are switched back once the
 * application terminates, so a long-lived process never carries them into
 * the next request.
 */
class UseDemoContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $previousGuard = auth()->getDefaultDriver();

        DemoContext::activate();

        auth()->shouldUse('demo');
        app(PortalContext::class)->forget();

        app()->terminating(static function () use ($previousGuard): void {
            DemoContext::deactivate();
            auth()->shouldUse($previousGuard);
        });

        return $next($request);
    }
}
