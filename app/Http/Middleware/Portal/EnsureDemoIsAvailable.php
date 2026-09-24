<?php

namespace App\Http\Middleware\Portal;

use App\Support\Demo\DemoDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware on every /demo request, including the login page.
 *
 * DEMO_PANEL_ENABLED=false turns the whole panel into a 404. Otherwise
 * the demo connection must name its own database — a misconfiguration
 * stops the request with an exception instead of letting the sandbox
 * run against the main database.
 */
class EnsureDemoIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('demo.panel_enabled'), 404);

        DemoDatabase::assertIsolated();

        return $next($request);
    }
}
