<?php

namespace App\Http\Middleware\Portal;

use App\Enums\PortalType;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines a portal user to their own portal's URL space.
 *
 * The Filament panels are guarded by User::canAccessPanel() (which
 * Filament also enforces on Livewire round-trips via its persistent
 * Authenticate middleware). This covers the OTHER half — the plain
 * routes in routes/web.php, which run under the `web` group and only
 * ask for `auth`. Without it, a trainee holding a valid session could
 * call /ocr-documents/{id}/file and pull a production document.
 *
 * The rule is a deny-by-default allowlist, not a blocklist of admin
 * paths: anything not demonstrably inside the user's own portal (or a
 * framework path the portal itself needs) is refused, so a route added
 * to web.php next month is protected without anyone remembering to.
 *
 * Users with no portal account — every existing LMS user — pass
 * straight through, which is why this changes nothing for them.
 */
class RestrictPortalUsers
{
    /**
     * Framework/asset paths a portal page legitimately needs. Livewire's
     * update endpoint is shared by all panels, but Filament registers
     * its own Authenticate as persistent Livewire middleware, so a
     * component belonging to another panel still fails canAccessPanel()
     * on that request.
     *
     * @var list<string>
     */
    protected array $sharedPaths = [
        'livewire/*',
        'livewire-*/*',
        'up',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $account = app(PortalContext::class)->account();

        if ($account === null) {
            return $next($request);
        }

        if (! $account->isUsable()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'This portal account is no longer active.');
        }

        if ($this->isAllowed($request, $account->portal)) {
            return $next($request);
        }

        /*
         * The site root redirects to /admin. Sending a portal user
         * there would 404 them for visiting "/", so they are pointed at
         * their own portal instead — which is both better behaviour and
         * one less way to notice that /admin exists.
         */
        if ($request->is('/')) {
            return Redirect::to('/'.$account->portal->pathPrefix());
        }

        abort(404);
    }

    protected function isAllowed(Request $request, PortalType $portal): bool
    {
        if ($request->is($portal->pathPrefix(), $portal->pathPrefix().'/*')) {
            return true;
        }

        return $request->is(...$this->sharedPaths);
    }
}
