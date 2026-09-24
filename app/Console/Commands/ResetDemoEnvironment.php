<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoResetService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Restores the sandbox after a prospect has been clicking around in it.
 *
 *     php artisan demo:reset
 *
 * Only ever touches the demo database: DemoResetService refuses to run
 * if the demo connection resolves to the main database, and only clears
 * demo_ tables on it. Demo logins (demo_users) are kept.
 */
class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset
        {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe and reseed the FYNN-ON demo sandbox (DEMO database only)';

    public function handle(DemoResetService $reset): int
    {
        if (! $this->option('force') && ! $this->confirm('Reset all sandbox data in the DEMO database?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        try {
            $counts = $reset->reset();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sandbox restored.');
        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
