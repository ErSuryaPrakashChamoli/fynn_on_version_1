<?php

namespace App\Console\Commands;

use Database\Seeders\DemoEnvironment\DemoEnvironmentSeeder;
use Illuminate\Console\Command;

/**
 * Rebuilds the client demo database from scratch, seeded relative to
 * today so targets, commitments and follow-ups are always current.
 *
 *     php artisan demo-environment:refresh --env=demo
 *
 * It drops every table, so it refuses to run unless the app is booted
 * as the demo environment AND DEMO_MODE is on — the live .env has
 * neither, so there is no way to point this at production data.
 */
class RefreshDemoEnvironment extends Command
{
    protected $signature = 'demo-environment:refresh {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe and reseed the client demo database (demo environment only)';

    public function handle(): int
    {
        if (! $this->isDemoEnvironment()) {
            $this->error('Refusing to run: this is not the demo environment. Use --env=demo with DEMO_MODE=true.');

            return self::FAILURE;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! $this->option('force') && ! $this->confirm("Drop and reseed every table in [{$database}]?", true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DemoEnvironmentSeeder::class, '--force' => true]);

        $this->info("Demo database [{$database}] rebuilt for ".today()->toFormattedDateString().'.');

        return self::SUCCESS;
    }

    private function isDemoEnvironment(): bool
    {
        return app()->environment('demo') && (bool) config('demo.enabled');
    }
}
