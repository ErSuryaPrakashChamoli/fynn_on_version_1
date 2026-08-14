<?php

namespace App\Listeners;

use App\Models\UserLoginSession;
use Illuminate\Auth\Events\Login;

class StartLoginSession
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        /*
         * Only track authenticated users.
         */
        if (! $user || ! $user->id) {
            return;
        }

        /*
         * Close any previous open session for this user.
         *
         * This protects us from situations where the browser was
         * closed/crashed and Laravel did not receive the logout event.
         */
        UserLoginSession::query()
            ->where('user_id', $user->id)
            ->whereNull('logout_at')
            ->update([
                'logout_at' => now(),
                'last_seen_at' => now(),
                'logout_reason' => 'new_login',
            ]);

        /*
         * Create a new login session.
         */
        $loginSession = UserLoginSession::create([
            'user_id' => $user->id,

            'employee_id' => $user->employee_id,

            /*
             * Laravel's current browser session ID.
             */
            'session_id' => session()->getId(),

            'login_at' => now(),

            'last_seen_at' => now(),

            'screen_time_seconds' => 0,

            'ip_address' => request()->ip(),

            'user_agent' => request()->userAgent(),
        ]);

        /*
         * Remember this login-session record in the
         * Laravel session so the logout listener can find it.
         */
        session()->put(
            'login_session_id',
            $loginSession->id
        );
    }
}
