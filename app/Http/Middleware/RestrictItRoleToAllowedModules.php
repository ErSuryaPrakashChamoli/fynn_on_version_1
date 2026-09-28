<?php

namespace App\Http\Middleware;

use App\Support\ItModuleAccess;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server-side half of the IT role's module restriction (see
 * App\Support\ItModuleAccess): hiding sidebar items alone would leave
 * every screen reachable by URL, so any panel route outside the allowed
 * list is redirected to the Reporting Hierarchy page instead.
 */
class RestrictItRoleToAllowedModules
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! ItModuleAccess::restricts($user)) {
            return $next($request);
        }

        if (ItModuleAccess::allowsRoute($request->route()?->getName())) {
            return $next($request);
        }

        return redirect()->to(ItModuleAccess::landingUrl());
    }
}
