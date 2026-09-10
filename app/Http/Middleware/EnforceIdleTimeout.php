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
 * minutes without touching anything, and once their login session has
 * been closed behind their back.
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

        $session = $this->trackedSession($request);

        if ($session === null) {
            return $next($request);
        }

        /*
         * The row was closed while this browser was not looking: the
         * `sessions:close-idle` sweeper reached it, or StartLoginSession
         * superseded it when the same account signed in elsewhere.
         *
         * Nothing used to act on that, so the browser kept a perfectly
         * usable panel on a login session the log already counts as
         * over — and the heartbeat, getting a 404 forever, reloaded the
         * page every few seconds without ever changing anything. Ending
         * the auth session here is what makes that single reload land on
         * the login page.
         */
        if ($session->logout_at !== null) {
            return $this->signOut($request, $this->endedElsewhereMessage($session));
        }

        if ($session->isIdle()) {
            $session->closeAsIdle();

            return $this->signOut($request, $this->idleMessage());
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

    /**
     * The login session this browser is holding, open or closed.
     *
     * A missing `login_session_id` means the browser signed in before
     * login sessions were tracked, and a missing row means the log was
     * pruned underneath it — neither is evidence that the session ended,
     * so both are left alone.
     */
    protected function trackedSession(Request $request): ?UserLoginSession
    {
        $id = $request->session()->get('login_session_id');

        if (! $id) {
            return null;
        }

        return UserLoginSession::query()
            ->whereKey($id)
            ->where('user_id', Auth::id())
            ->first();
    }

    protected function idleMessage(): string
    {
        $minutes = UserLoginSession::idleTimeoutMinutes();

        return "You were signed out after {$minutes} minutes of inactivity. Please sign in again.";
    }

    protected function endedElsewhereMessage(UserLoginSession $session): string
    {
        return match ($session->logout_reason) {
            'new_login' => 'You were signed out because this account was signed in somewhere else. Please sign in again.',
            UserLoginSession::REASON_SESSION_TIMEOUT => $this->idleMessage(),
            default => 'Your session has ended. Please sign in again.',
        };
    }

    /**
     * Log out and send the user back to the panel's own login page.
     *
     * The Logout event fires, so EndLoginSession runs as usual — it finds
     * the row already closed above and leaves the session_timeout reason
     * in place rather than overwriting it with a plain "logout".
     */
    protected function signOut(Request $request, string $message): Response
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

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
            ->title('Signed out')
            ->body($message)
            ->warning()
            ->persistent()
            ->send();

        return redirect()->to(filament()->getLoginUrl());
    }
}
