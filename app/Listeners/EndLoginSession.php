<?php

namespace App\Listeners;

use App\Models\UserLoginSession;
use Illuminate\Auth\Events\Logout;

class EndLoginSession
{
    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (! $user || ! $user->id) {
            return;
        }

        /*
         * Get the login-session record created when
         * this browser session logged in.
         */
        $loginSessionId = session()->get('login_session_id');

        /*
         * First try the exact session record.
         */
        if ($loginSessionId) {
            $loginSession = UserLoginSession::query()
                ->where('id', $loginSessionId)
                ->where('user_id', $user->id)
                ->whereNull('logout_at')
                ->first();

            if ($loginSession) {
                $loginSession->update([
                    'logout_at' => now(),
                    // 'last_seen_at' => now(),
                    'logout_reason' => 'logout',
                ]);

                session()->forget('login_session_id');

                return;
            }
        }

        /*
         * Fallback:
         * If the session ID is unavailable, close the latest
         * open session for this user.
         */
        $loginSession = UserLoginSession::query()
            ->where('user_id', $user->id)
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();

        if ($loginSession) {
            $loginSession->update([
                'logout_at' => now(),
                // 'last_seen_at' => now(),
                'logout_reason' => 'logout',
            ]);
        }

        session()->forget('login_session_id');
    }
}
