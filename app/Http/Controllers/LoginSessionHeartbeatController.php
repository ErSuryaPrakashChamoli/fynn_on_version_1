<?php

namespace App\Http\Controllers;

use App\Models\UserLoginSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginSessionHeartbeatController extends Controller
{
    /**
     * Receive browser heartbeat and update active screen time.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
         * Get the login session created during login.
         */
        $loginSessionId = $request->session()->get('login_session_id');

        if (! $loginSessionId) {
            return response()->json([
                'message' => 'Login session not found.',
            ], 404);
        }

        $loginSession = UserLoginSession::query()
            ->where('id', $loginSessionId)
            ->where('user_id', $user->id)
            ->whereNull('logout_at')
            ->first();

        if (! $loginSession) {
            return response()->json([
                'message' => 'Active login session not found.',
            ], 404);
        }

        /*
         * Whether the FYNN-ON browser tab is currently visible.
         *
         * The frontend sends:
         *
         * active = true
         * active = false
         */
        $active = $request->boolean('active');

        $now = now();

        /*
         * Only count time when the browser tab is visible.
         */
        if ($active && $loginSession->last_seen_at) {

            $elapsedSeconds = $loginSession->last_seen_at->diffInSeconds($now);

            /*
             * Safety limit.
             *
             * Even if a browser was frozen for several minutes,
             * we don't want to accidentally count a huge amount
             * of screen time.
             *
             * Maximum counted interval = 60 seconds.
             */
            $countedSeconds = min(
                max($elapsedSeconds, 0),
                60
            );

            if ($countedSeconds > 0) {
                $loginSession->screen_time_seconds += $countedSeconds;
            }

            /*
             * Update last active time.
             */
            $loginSession->last_seen_at = $now;
        }

        /*
         * When the browser is hidden/inactive, deliberately DON'T
         * update last_seen_at.
         *
         * This prevents several hours of hidden browser time from
         * being counted when the user comes back.
         */
        $loginSession->save();

        return response()->json([
            'success' => true,
            'active' => $active,
            'screen_time_seconds' => $loginSession->screen_time_seconds,
        ]);
    }
}
