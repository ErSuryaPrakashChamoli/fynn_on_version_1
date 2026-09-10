<?php

namespace App\Console\Commands;

use App\Models\UserLoginSession;
use Illuminate\Console\Command;

/**
 * Closes login sessions nobody came back to.
 *
 *     php artisan sessions:close-idle
 *
 * EnforceIdleTimeout can only act when the user makes another request —
 * someone who shuts the laptop lid never makes one, and their row would
 * otherwise sit open forever, showing as "Active" in the login log and
 * inflating session duration. This sweeper is what keeps the log honest,
 * and it writes the same session_timeout reason the middleware does so
 * the two are indistinguishable in reporting.
 */
class CloseIdleLoginSessions extends Command
{
    protected $signature = 'sessions:close-idle {--dry-run : List what would be closed without changing anything}';

    protected $description = 'Close login sessions that have been idle past the configured timeout';

    public function handle(): int
    {
        if (! UserLoginSession::idleTimeoutEnabled()) {
            $this->comment('Idle logout is disabled (session.idle_timeout is 0).');

            return self::SUCCESS;
        }

        $minutes = UserLoginSession::idleTimeoutMinutes();

        $sessions = UserLoginSession::query()
            ->idle()
            ->with('employee')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info("No sessions idle beyond {$minutes} minutes.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Session', 'Employee', 'Login at', 'Last interaction'],
                $sessions->map(fn (UserLoginSession $session): array => [
                    $session->getKey(),
                    $session->employee?->emp_name ?? 'N/A',
                    $session->login_at?->format('d M Y H:i'),
                    $session->lastInteractionAt()?->format('d M Y H:i'),
                ])->all(),
            );

            $this->comment("{$sessions->count()} session(s) would be closed.");

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($sessions as $session) {
            /*
             * Closed at the moment the session actually went idle, not at
             * the moment this command happened to run — otherwise a
             * sweeper running every five minutes would credit up to five
             * extra minutes of session duration to everyone it closes.
             */
            $closedAt = $session->lastInteractionAt()?->copy()->addMinutes($minutes);

            if ($session->closeAsIdle($closedAt)) {
                $closed++;
            }
        }

        $this->info("Closed {$closed} idle session(s) after {$minutes} minutes of inactivity.");

        return self::SUCCESS;
    }
}
