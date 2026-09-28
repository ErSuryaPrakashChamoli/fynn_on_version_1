<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caches a follow-up calendar's day chips per widget, user, database and
 * range. Working out every follow-up's outcome for a month is the heaviest
 * part of the Dashboard, and the same month is asked for on every load.
 *
 * Any change to a follow-up, assignment or lead bumps one version number,
 * which retires every cached month at once. The short TTL covers what no
 * write announces: time passing (an open follow-up turning missed once its
 * grace period ends).
 */
class FollowUpCalendarCache
{
    public const TTL_SECONDS = 300;

    private const VERSION_KEY = 'follow-up-calendar:version';

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public static function remember(string $widget, ?int $userId, string $start, string $end, Closure $compute): mixed
    {
        $key = implode(':', [
            'follow-up-calendar',
            self::version(),
            // The /demo panel runs the same widgets against its own database.
            DB::connection()->getName(),
            DB::connection()->getDatabaseName(),
            class_basename($widget),
            $userId ?? 'guest',
            $start,
            $end,
        ]);

        return Cache::remember($key, self::TTL_SECONDS, $compute);
    }

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }
}
