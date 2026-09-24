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
 * The panel authenticates on the `demo` guard, so the only user this
 * can ever see is a DemoUser from the demo database — a main LMS user
 * signed in to /admin is simply a guest here. An account that has been
 * switched off or has expired is signed out on its next request.
 */
class EnsureDemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof DemoUser, 403);

        if (! $user->isUsable()) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'This demo account is no longer active.');
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
