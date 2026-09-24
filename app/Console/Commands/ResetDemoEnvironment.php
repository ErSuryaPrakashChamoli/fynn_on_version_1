<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Restores the demo environment after a prospect has been clicking around
 * in it.
 *
 *     php artisan demo:reset
 *
 * Rebuilds the DEMO database from scratch — drops its tables, re-runs the
 * application migrations on it and re-seeds the demo dataset and logins —
 * through `demo:migrate --fresh --seed`, which refuses to run at all if the
 * demo connection resolves to the main database. The main database is
 * never touched.
 */
class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset
        {--force : Skip the confirmation prompt}';

    protected $description = 'Rebuild and reseed the DEMO database (demo environment only)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Rebuild the DEMO database? All demo data will be replaced with the seeded dataset.', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        return $this->call('demo:migrate', [
            '--fresh' => true,
            '--seed' => true,
            '--force' => true,
        ]);
    }
}
