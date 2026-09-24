<?php

namespace App\Services\Demo;

use App\Support\Demo\DemoDatabase;
use Database\Seeders\Demo\DemoDataSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Restores the sandbox to its shipped state.
 *
 * Every statement runs on the demo connection, never the default one,
 * and only against the tables listed in TABLES. assertDemoOnly()
 * re-checks at runtime that the connection is really a separate
 * database and that every table is a demo_ table, so a misconfigured
 * .env or a table added to the list by mistake stops the reset rather
 * than deleting live data.
 *
 * demo_users is deliberately not in the list: resetting the dataset
 * between prospects must not lock the salesperson out.
 *
 * Ordered child-first so the foreign keys hold during the delete without
 * needing to disable constraint checks.
 */
class DemoResetService
{
    /**
     * @var list<string>
     */
    public const TABLES = [
        'demo_follow_ups',
        'demo_applications',
        'demo_customers',
        'demo_leads',
        'demo_employees',
        'demo_loan_products',
        'demo_banks',
    ];

    /**
     * Wipe and reseed the sandbox dataset.
     *
     * @return array<string, int> row counts after reseeding
     */
    public function reset(): array
    {
        $this->assertDemoOnly();

        $connection = $this->connection();

        $connection->transaction(function () use ($connection): void {
            foreach (self::TABLES as $table) {
                $connection->table($table)->delete();
            }
        });

        app(DemoDataSeeder::class)->run();

        $counts = $this->counts();

        Log::info('Demo environment reset', [
            'connection' => $connection->getName(),
            'database' => $connection->getDatabaseName(),
            'row_counts' => $counts,
        ]);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = $this->connection()->table($table)->count();
        }

        return $counts;
    }

    protected function connection(): Connection
    {
        return DB::connection(DemoDatabase::connectionName());
    }

    /**
     * The connection must be the separate demo database, and every table
     * this service may delete from must be a demo_ table that exists on
     * it. Anything else is a configuration or programming error, and
     * finding out here is much cheaper than finding out afterwards.
     */
    protected function assertDemoOnly(): void
    {
        DemoDatabase::assertIsolated();

        $schema = $this->connection()->getSchemaBuilder();

        foreach (self::TABLES as $table) {
            if (! str_starts_with($table, 'demo_')) {
                throw new RuntimeException(
                    "Refusing to reset [{$table}] — only demo_ tables may be reset."
                );
            }

            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Demo table [{$table}] does not exist. Run `php artisan demo:migrate` first.");
            }
        }
    }
}
