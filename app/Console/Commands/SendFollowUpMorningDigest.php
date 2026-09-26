<?php

namespace App\Console\Commands;

use App\Services\FollowUpEscalationService;
use Illuminate\Console\Command;

/**
 * Puts a summary of the team's follow-ups in every supervisor's (and
 * Admin's) bell: due today, callers over the daily limit, newly missed and
 * the open backlog.
 *
 *     php artisan follow-ups:morning-digest
 *
 * Sent at most once a day per supervisor.
 */
class SendFollowUpMorningDigest extends Command
{
    protected $signature = 'follow-ups:morning-digest';

    protected $description = "Send supervisors a morning summary of their team's follow-ups";

    public function handle(FollowUpEscalationService $escalations): int
    {
        $sent = $escalations->sendMorningDigests();

        $this->info("{$sent} follow-up summar(ies) sent.");

        return self::SUCCESS;
    }
}
