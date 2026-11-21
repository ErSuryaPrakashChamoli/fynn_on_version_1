<?php

namespace App\Console\Commands;

use App\Services\CustomerCashbackService;
use Illuminate\Console\Command;

/**
 * Moves a Team Leader's cashback form to the Admin once its supervisor has
 * let the 24-hour approval time (TAT) run out, and tells the Admin, the
 * supervisor and whoever filled it.
 *
 *     php artisan cashback:escalate-overdue
 */
class EscalateOverdueCashbackForms extends Command
{
    protected $signature = 'cashback:escalate-overdue';

    protected $description = 'Move cashback forms past the supervisor\'s 24-hour approval time to the Admin';

    public function handle(CustomerCashbackService $cashback): int
    {
        $moved = $cashback->escalateOverdue();

        $this->info("{$moved} cashback form(s) moved to the Admin.");

        return self::SUCCESS;
    }
}
