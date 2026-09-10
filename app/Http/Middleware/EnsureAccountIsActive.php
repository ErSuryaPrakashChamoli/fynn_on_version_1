<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserLoginSession;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out anyone whose account has been switched off while they were
 * still logged in — a deactivated user row, or an employee marked as
 * having left (employees.exit_status).
 *
 * User::canAccessPanel() is what actually keeps them out: it refuses the
 * login attempt and every subsequent panel request. This middleware runs
 * BEFORE Filament's Authenticate so that an already-open session ends as
 * a clean sign-out with an explanation instead of a bare 403 page on
 * whichever screen they happened to be on when Admin approved the exit.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || ! $user->isDeactivated()) {
            return $next($request);
        }

        $this->closeLoginSession($request);

        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your account is no longer active. Please contact your administrator.';

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
            ->title('Account deactivated')
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        return redirect()->to(filament()->getLoginUrl());
    }

    /**
     * Close the login-session row with its own reason, so the login log
     * says why the session ended rather than crediting the user with a
     * logout they never performed. The Logout event's EndLoginSession
     * listener then finds the row already closed and leaves it alone.
     */
    protected function closeLoginSession(Request $request): void
    {
        $id = $request->session()->get('login_session_id');

        if (! $id) {
            return;
        }

        UserLoginSession::query()
            ->whereKey($id)
            ->where('user_id', Auth::id())
            ->whereNull('logout_at')
            ->first()
            ?->closeAsDeactivated();
    }
}
