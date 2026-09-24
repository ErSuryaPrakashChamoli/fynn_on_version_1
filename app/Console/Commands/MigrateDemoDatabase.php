<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoDatabase;
use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The only supported way to run the sandbox's migrations.
 *
 *     php artisan demo:migrate                 # migrate the DEMO database
 *     php artisan demo:migrate --seed          # ... then seed the demo login + dataset
 *     php artisan demo:migrate --fresh --seed  # drop every DEMO table and rebuild
 *     php artisan demo:migrate --rollback      # roll back the last DEMO batch
 *
 * Always passes --database=demo and --path=database/migrations/demo, and
 * refuses to start if the demo connection resolves to the main database,
 * so there is no invocation of this command that can migrate, wipe or
 * seed the main database. The plain `php artisan migrate` never sees
 * these migrations: it does not scan subdirectories.
 */
class MigrateDemoDatabase extends Command
{
    protected $signature = 'demo:migrate
        {--fresh : Drop all tables in the DEMO database and re-run every demo migration}
        {--rollback : Roll back the last batch of demo migrations}
        {--seed : Seed the demo login and sandbox dataset afterwards}
        {--force : Run without confirmation in production}';

    protected $description = 'Run the demo sandbox migrations against the DEMO database only';

    public function handle(): int
    {
        try {
            DemoDatabase::assertIsolated();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $connection = DemoDatabase::connectionName();
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        $this->components->info("Target: DEMO database [{$database}] on [{$host}] via connection [{$connection}].");

        $command = match (true) {
            (bool) $this->option('fresh') => 'migrate:fresh',
            (bool) $this->option('rollback') => 'migrate:rollback',
            default => 'migrate',
        };

        $options = [
            '--database' => $connection,
            '--path' => DemoDatabase::MIGRATIONS_PATH,
            '--force' => (bool) $this->option('force'),
        ];

        $status = $this->call($command, $options);

        if ($status !== self::SUCCESS || ! $this->option('seed') || $this->option('rollback')) {
            return $status;
        }

        return $this->call('db:seed', [
            '--class' => DemoDatabaseSeeder::class,
            '--database' => $connection,
            '--force' => (bool) $this->option('force'),
        ]);
    }
}
