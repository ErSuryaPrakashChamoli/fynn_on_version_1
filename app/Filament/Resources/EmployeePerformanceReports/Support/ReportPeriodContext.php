<?php

namespace App\Filament\Resources\EmployeePerformanceReports\Support;

use App\Support\Performance\PerformancePeriod;
use Carbon\Carbon;

/**
 * Carries the period selected via the table's "period" filter into the
 * column closures that compute per-employee metrics — set once per
 * request from ListEmployeePerformanceReports::table(), read by
 * EmployeePerformanceReportsTable's column state closures and the
 * matching Exporter. Request-scoped only: PHP tears this down between
 * requests, so it never leaks state across users/requests.
 */
class ReportPeriodContext
{
    private static ?string $periodType = null;

    private static ?Carbon $reference = null;

    private static ?Carbon $customStart = null;

    private static ?Carbon $customEnd = null;

    public static function set(string $periodType, Carbon $reference, ?Carbon $customStart = null, ?Carbon $customEnd = null): void
    {
        self::$periodType = $periodType;
        self::$reference = $reference;
        self::$customStart = $customStart;
        self::$customEnd = $customEnd;
    }

    public static function periodType(): string
    {
        return self::$periodType ?? PerformancePeriod::MONTHLY;
    }

    public static function reference(): Carbon
    {
        return self::$reference ?? now();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(): array
    {
        return PerformancePeriod::range(self::periodType(), self::reference(), self::$customStart, self::$customEnd);
    }
}
