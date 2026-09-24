<?php

namespace App\Http\Middleware\Portal;

use App\Models\Demo\DemoUser;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel-level guard for /demo.
 *
 * The panel authenticates on the `demo` guard, whose provider only ever
 * returns a DemoUser (a user row in the DEMO database). This re-asserts
 * that on every request and Livewire round-trip, so nothing but a demo
 * login can ever drive the demo panel.
 */
class EnsureDemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Filament::auth()->user() instanceof DemoUser, 403);

        return $next($request);
    }
}
