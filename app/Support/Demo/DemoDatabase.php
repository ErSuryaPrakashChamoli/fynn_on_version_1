<?php

namespace App\Support\Demo;

use RuntimeException;

/**
 * The single answer to "which database does the sandbox use, and is it
 * really separate from the main one?".
 *
 * Every Demo model is hard-bound to connectionName(), so a misconfigured
 * .env is the one remaining way the sandbox could reach main data — for
 * example DEMO_DB_DATABASE copied from DB_DATABASE. assertIsolated() is
 * called by the /demo panel on every request and by every demo artisan
 * command, and refuses to continue rather than fall back.
 */
class DemoDatabase
{
    public const MIGRATIONS_PATH = 'database/migrations/demo';

    public static function connectionName(): string
    {
        return (string) config('demo.connection', 'demo');
    }

    /**
     * The main application's connection, as configured — not the runtime
     * default, which `db:seed --database=demo` temporarily switches.
     */
    public static function mainConnectionName(): string
    {
        return (string) config('demo.main_connection', config('database.default'));
    }

    /**
     * @throws RuntimeException when the demo connection is missing, has no
     *                          database name, or resolves to the main database
     */
    public static function assertIsolated(): void
    {
        $demoName = static::connectionName();
        $mainName = static::mainConnectionName();

        $demo = config("database.connections.{$demoName}");
        $main = config("database.connections.{$mainName}");

        if (! is_array($demo)) {
            throw new RuntimeException("The demo database connection [{$demoName}] is not configured.");
        }

        if ($demoName === $mainName) {
            throw new RuntimeException('The demo connection must not be the main connection.');
        }

        if (blank($demo['url'] ?? null) && blank($demo['database'] ?? null)) {
            throw new RuntimeException('DEMO_DB_DATABASE is not set. Refusing to run the demo panel without its own database.');
        }

        if (is_array($main) && static::pointsAtSameDatabase($demo, $main)) {
            throw new RuntimeException('The demo database resolves to the main database. Set DEMO_DB_DATABASE to a separate database.');
        }
    }

    /**
     * @param  array<string, mixed>  $demo
     * @param  array<string, mixed>  $main
     */
    protected static function pointsAtSameDatabase(array $demo, array $main): bool
    {
        if (filled($demo['url'] ?? null) || filled($main['url'] ?? null)) {
            return ($demo['url'] ?? null) === ($main['url'] ?? null);
        }

        // Two SQLite in-memory connections are two separate databases.
        if (($demo['database'] ?? null) === ':memory:') {
            return false;
        }

        return ($demo['driver'] ?? null) === ($main['driver'] ?? null)
            && ($demo['database'] ?? null) === ($main['database'] ?? null)
            && (string) ($demo['host'] ?? '') === (string) ($main['host'] ?? '')
            && (string) ($demo['port'] ?? '') === (string) ($main['port'] ?? '');
    }
}
