<?php

namespace App\Console\Commands;

use App\Models\DailyCommitment;
use App\Services\DailyCommitmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Freezes a day's commitments: recomputes each one's achievement from the
 * customer journey and settles it to MET / OVERACHIEVED / FAILED. The
 * dashboards always compute live, so this exists purely to leave a
 * correct historical snapshot on the row once the day is over.
 */
class SettleDailyCommitments extends Command
{
    protected $signature = 'daily-commitment:settle {--date= : The day to settle (defaults to yesterday)}';

    protected $description = 'Recompute and settle the daily commitments for a given day';

    public function handle(DailyCommitmentService $service): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : today()->subDay();

        $commitments = DailyCommitment::query()->forDate($date)->get();

        if ($commitments->isEmpty()) {
            $this->info("No commitments to settle for {$date->toDateString()}.");

            return self::SUCCESS;
        }

        foreach ($commitments as $commitment) {
            $service->syncCommitment($commitment);
        }

        $settled = $commitments->countBy(fn (DailyCommitment $commitment): string => $commitment->result->value);

        $this->info("Settled {$commitments->count()} commitment(s) for {$date->toDateString()}.");
        $this->table(['Result', 'Count'], $settled->map(fn (int $count, string $result): array => [$result, $count])->values());

        return self::SUCCESS;
    }
}
