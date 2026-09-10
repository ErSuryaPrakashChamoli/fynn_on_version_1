<?php

namespace App\Http\Middleware\Portal;

use App\Enums\PortalType;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel-level guard for /demo.
 *
 * Unlike the Academy, internal LMS users are NOT waved through on the
 * strength of a role — a demo session is a sales artefact and every
 * visitor needs a demo portal account, so the tenant scoping below has
 * something real to scope by.
 */
class EnsureDemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = app(PortalContext::class)->account();

        abort_if($account === null, 403);
        abort_unless($account->portal === PortalType::Demo && $account->isUsable(), 403);

        $account->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
