<?php

namespace App\Support;

use App\Models\Employee;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The reporting data ReportingTree and ReportingTimeline are built from,
 * read once and shared by everything that asks for it during a request,
 * queue job or test.
 *
 * Before this, every hierarchy walk re-read the whole employees table and
 * employee_reporting_history and rebuilt every tree. The top-performer
 * marquee alone did that 52 times on one dashboard load (about 2.3 s and
 * 345 queries).
 *
 * The data can never go stale, because any write to either table clears
 * the memo straight away. AppServiceProvider watches every executed
 * statement (Eloquent saves, mass updates, raw DB::table() writes alike)
 * through changesReportingData(), and clears on every transaction
 * rollback too. A request that creates or re-points employees and then
 * walks the tree still sees its own change, exactly as when every call
 * read the database.
 *
 * Bound as a scoped instance, so the memo is dropped between queue jobs
 * and never outlives the application instance (a fresh one per test). It
 * is keyed by database connection as well, so a /demo request (whose
 * default connection DemoContext switches mid-request) can never read
 * the main company's tree.
 */
final class ReportingSnapshot
{
    /**
     * Tables a reporting tree is built from. A write to any of them
     * retires the memo.
     */
    public const TABLES = ['employees', 'employee_reporting_history'];

    /**
     * @var array<string, array<string, mixed>> connection name => memo key => value
     */
    private array $memo = [];

    /**
     * $build's result, built at most once per connection until the
     * reporting data changes.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $build
     * @return TValue
     */
    public static function remember(string $key, Closure $build): mixed
    {
        return app(self::class)->rememberOn((new Employee)->getConnectionName() ?? DB::getDefaultConnection(), $key, $build);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $build
     * @return TValue
     */
    public function rememberOn(string $connection, string $key, Closure $build): mixed
    {
        if (! array_key_exists($key, $this->memo[$connection] ?? [])) {
            $this->memo[$connection][$key] = $build();
        }

        return $this->memo[$connection][$key];
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Whether an executed SQL statement may have changed the reporting
     * data: any write statement that names one of TABLES. Erring on the
     * side of clearing costs one extra read, never a wrong tree.
     */
    public static function changesReportingData(string $sql): bool
    {
        if (stripos($sql, 'employee') === false) {
            return false;
        }

        return preg_match('/^\s*(?:insert|update|delete|replace|truncate|alter|drop|rename)\b/i', $sql) === 1
            && preg_match('/\b(?:'.implode('|', self::TABLES).')\b/i', $sql) === 1;
    }
}
