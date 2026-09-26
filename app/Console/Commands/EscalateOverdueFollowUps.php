<?php

namespace App\Console\Commands;

use App\Services\FollowUpEscalationService;
use Illuminate\Console\Command;

/**
 * Sends follow-ups still open 48 hours after their time to the owner's
 * boss, and those still open after 7 days to the boss above as well.
 *
 *     php artisan follow-ups:escalate
 *
 * Each level fires once per follow-up (follow_ups.escalation_level).
 */
class EscalateOverdueFollowUps extends Command
{
    protected $signature = 'follow-ups:escalate';

    protected $description = 'Escalate long-overdue follow-ups up the reporting line';

    public function handle(FollowUpEscalationService $escalations): int
    {
        $escalated = $escalations->escalate();

        $this->info("{$escalated} follow-up(s) escalated.");

        return self::SUCCESS;
    }
}
