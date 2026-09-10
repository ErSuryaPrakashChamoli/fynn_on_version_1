<?php

namespace App\Http\Middleware\Portal;

use App\Enums\PortalType;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel-level guard for /academy.
 *
 * User::canAccessPanel() already refuses the wrong audience; this is the
 * second, independent check so that a future change to that method (or a
 * route registered on the panel outside Filament's page machinery)
 * cannot silently open the panel up. It also refreshes last_seen_at,
 * which the demo-expiry reporting reads.
 */
class EnsureAcademyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(PortalContext::class);
        $account = $context->account();

        if ($account === null) {
            // An internal LMS user (Admin/trainer-manager) reaching the
            // Academy to author content. Allowed, but only for users the
            // LMS itself considers administrative.
            abort_unless(auth()->user()?->hasRole('Admin'), 403);

            return $next($request);
        }

        abort_unless($account->portal === PortalType::Academy && $account->isUsable(), 403);

        $account->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
