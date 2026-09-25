<?php

namespace App\Console\Commands;

use App\Services\FollowUpReminderService;
use Illuminate\Console\Command;

/**
 * Puts every follow-up coming due into its owner's bell (and so into the
 * reminder pop-up).
 *
 *     php artisan follow-ups:send-reminders
 *
 * Each follow-up is reminded once: FollowUpReminderService stamps
 * follow_ups.reminded_at, and a reschedule logs a fresh row that is
 * reminded again when its new time comes.
 */
class SendFollowUpReminders extends Command
{
    protected $signature = 'follow-ups:send-reminders';

    protected $description = 'Notify owners of follow-ups that are coming due';

    public function handle(FollowUpReminderService $reminders): int
    {
        $sent = $reminders->sendDueReminders();

        $this->info("{$sent} follow-up reminder(s) sent.");

        return self::SUCCESS;
    }
}
