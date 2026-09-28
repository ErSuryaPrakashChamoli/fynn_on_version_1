<?php

namespace App\Console\Commands;

use App\Services\HelpDesk\ComplaintEscalationService;
use Illuminate\Console\Command;

/**
 * Sends help-desk tickets past their SLA deadline to the handler's
 * supervisor (level 1), and after a second SLA window to the boss above
 * (level 2), falling back to the Admins.
 *
 *     php artisan complaints:escalate
 */
class EscalateOverdueComplaints extends Command
{
    protected $signature = 'complaints:escalate';

    protected $description = 'Escalate overdue help-desk tickets up the reporting line';

    public function handle(ComplaintEscalationService $escalations): int
    {
        $escalated = $escalations->escalateOverdue();

        $this->info("{$escalated} ticket(s) escalated.");

        return self::SUCCESS;
    }
}
