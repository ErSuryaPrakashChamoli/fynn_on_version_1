<?php

namespace Tests;

use App\Support\Demo\DemoDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * Builds the demo database for a test.
 *
 * phpunit.xml points the demo connection at its own SQLite :memory:
 * database — a genuinely separate database from the default one — so
 * tests exercise the same two-database split as production. Use it
 * alongside RefreshDatabase, which handles the main database.
 */
trait RefreshDemoDatabase
{
    protected function setUpRefreshDemoDatabase(): void
    {
        Artisan::call('migrate', [
            '--database' => DemoDatabase::connectionName(),
            '--path' => DemoDatabase::MIGRATIONS_PATH,
        ]);
    }
}
