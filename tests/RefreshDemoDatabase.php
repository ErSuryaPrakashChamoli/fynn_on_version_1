<?php

namespace Tests;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * Builds the demo database for a test.
 *
 * phpunit.xml points the demo connection at its own SQLite :memory:
 * database — a genuinely separate database from the default one — and
 * this runs the application's normal migrations against it inside the
 * demo context, exactly as `php artisan demo:migrate` does. Use it
 * alongside RefreshDatabase, which handles the main database.
 */
trait RefreshDemoDatabase
{
    protected function setUpRefreshDemoDatabase(): void
    {
        DemoContext::run(fn (): int => Artisan::call('migrate', [
            '--database' => DemoDatabase::connectionName(),
            '--path' => DemoDatabase::MIGRATIONS_PATH,
        ]));
    }
}
