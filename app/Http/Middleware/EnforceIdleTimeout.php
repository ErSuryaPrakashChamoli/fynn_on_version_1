<?php

namespace App\Http\Middleware;

use App\Models\UserLoginSession;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out of the LMS once they have gone `session.idle_timeout`
 * minutes without touching anything.
 *
 * This is the authority, not the browser. The countdown in
 * login-session-heartbeat.js is there so the user gets a warning and a
 * clean redirect instead of a surprise; this middleware is what makes
 * the timeout real for a tab with JavaScript disabled, a stale page
 * restored from bfcache, or a request replayed by hand.
 *
 * Idleness is measured from user_login_sessions.last_activity_at, which
 * only the heartbeat's `interacted` flag and a genuine panel request
 * advance — deliberately NOT last_seen_at, which keeps moving for a tab
 * merely left visible on an unattended screen.
 *
 * Livewire's own update endpoint does not carry panel auth middleware,
 * so widget polling never passes through here and cannot keep an
 * abandoned session alive.
 */
class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! UserLoginSession::idleTimeoutEnabled() || ! Auth::check()) {
            return $next($request);
        }

        $session = $this->currentSession($request);

        if ($session === null) {
            return $next($request);
        }

        if ($session->isIdle()) {
            $session->closeAsIdle();

            return $this->signOut($request);
        }

        /*
         * A real page request IS an interaction — the user clicked
         * something to get here. Recording it keeps an actively-used
         * panel alive even if the heartbeat request is blocked (an ad
         * blocker, a proxy, a lost websocket).
         */
        $session->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $next($request);
    }

    protected function currentSession(Request $request): ?UserLoginSession
    {
        $id = $request->session()->get('login_session_id');

        if (! $id) {
            return null;
        }

        return UserLoginSession::query()
            ->whereKey($id)
            ->where('user_id', Auth::id())
            ->whereNull('logout_at')
            ->first();
    }

    /**
     * Log out and send the user back to the panel's own login page.
     *
     * The Logout event fires, so EndLoginSession runs as usual — it finds
     * the row already closed above and leaves the session_timeout reason
     * in place rather than overwriting it with a plain "logout".
     */
    protected function signOut(Request $request): Response
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $minutes = UserLoginSession::idleTimeoutMinutes();
        $message = "You were signed out after {$minutes} minutes of inactivity. Please sign in again.";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        /*
         * A Filament notification rather than a `status` flash: the login
         * page is a Filament page and its layout renders the
         * notifications component, whereas nothing on it reads a plain
         * session flash.
         */
        Notification::make()
            ->title('Signed out for inactivity')
            ->body($message)
            ->warning()
            ->persistent()
            ->send();

        return redirect()->to(filament()->getLoginUrl());
    }
}
