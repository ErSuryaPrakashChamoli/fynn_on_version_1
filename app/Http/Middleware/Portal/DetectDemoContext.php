<?php

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts Filament's shared download routes into the demo environment when
 * they are called from /demo.
 *
 * Filament serves export downloads and failed-import CSVs from routes that
 * belong to no panel (/filament/exports/{export}/download, ...). The URLs
 * it builds on a non-default guard carry `authGuard=demo`, and the
 * controllers then authenticate against that guard — so the request must
 * also resolve {export}/{import} and the file from the DEMO database and
 * storage. Runs ahead of route-model binding (see the priority list in
 * bootstrap/app.php).
 *
 * Safe against a forged parameter: in the demo context the controller
 * checks the `demo` guard, where a main-database session is simply a
 * guest, so the request is refused rather than served.
 */
class DetectDemoContext
{
    /**
     * @var list<string>
     */
    protected array $sharedFilamentPaths = [
        'filament/exports/*',
        'filament/imports/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('authGuard') === 'demo' && $request->is(...$this->sharedFilamentPaths)) {
            return app(UseDemoContext::class)->handle($request, $next);
        }

        return $next($request);
    }
}
