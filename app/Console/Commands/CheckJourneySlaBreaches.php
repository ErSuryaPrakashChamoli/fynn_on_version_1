<?php

namespace App\Console\Commands;

use App\Services\Journey\JourneySlaService;
use Illuminate\Console\Command;

class CheckJourneySlaBreaches extends Command
{
    protected $signature = 'journey:check-sla-breaches';

    protected $description = 'Detect Customer Journey Manager-stage SLA breaches, raise reminders, and escalate overdue cases to the Cluster Manager (notification only — never grants access)';

    public function handle(JourneySlaService $service): int
    {
        $result = $service->checkBreaches();

        $this->info(sprintf(
            'SLA check complete — %d reminder(s), %d escalation(s), %d resolved.',
            $result['reminders'],
            $result['escalations'],
            $result['resolved'],
        ));

        return self::SUCCESS;
    }
}
