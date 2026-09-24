<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoDatabase;
use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * The only supported way to build the demo database.
 *
 *     php artisan demo:migrate                 # run the app's migrations on the DEMO database
 *     php artisan demo:migrate --seed          # ... then seed the demo dataset and logins
 *     php artisan demo:migrate --fresh --seed  # drop every DEMO table and rebuild
 *     php artisan demo:migrate --rollback      # roll back the last DEMO batch
 *
 * The demo database carries the SAME schema as the main one — these are
 * the normal database/migrations files — because /demo runs the same
 * admin code. Every run is pinned to the demo connection (--database) and
 * executes inside the demo context, and refuses to start at all if the
 * demo connection resolves to the main database. There is no invocation
 * of this command that can migrate, wipe or seed the main database.
 */
class MigrateDemoDatabase extends Command
{
    protected $signature = 'demo:migrate
        {--fresh : Drop all tables in the DEMO database and re-run every migration}
        {--rollback : Roll back the last batch of migrations on the DEMO database}
        {--seed : Seed the demo dataset and demo logins afterwards}
        {--force : Run without confirmation in production}';

    protected $description = 'Run the application migrations against the DEMO database only';

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

        /*
         * A migration may name its own connection (Telescope's does). Any
         * that would run anywhere but the demo database stops the run
         * before its first statement.
         */
        Event::listen(MigrationStarted::class, function (MigrationStarted $event) use ($connection): void {
            $target = $event->migration->getConnection();

            if ($target !== null && $target !== $connection) {
                throw new RuntimeException('Refusing to run '.$event->migration::class." on connection [{$target}] — demo:migrate only ever touches [{$connection}].");
            }
        });

        return DemoContext::run(function () use ($connection): int {
            $command = match (true) {
                (bool) $this->option('fresh') => 'migrate:fresh',
                (bool) $this->option('rollback') => 'migrate:rollback',
                default => 'migrate',
            };

            $status = $this->call($command, [
                '--database' => $connection,
                '--path' => DemoDatabase::MIGRATIONS_PATH,
                '--force' => (bool) $this->option('force'),
            ]);

            if ($status !== self::SUCCESS || ! $this->option('seed') || $this->option('rollback')) {
                return $status;
            }

            return $this->call('db:seed', [
                '--class' => DemoDatabaseSeeder::class,
                '--database' => $connection,
                '--force' => (bool) $this->option('force'),
            ]);
        });
    }
}
