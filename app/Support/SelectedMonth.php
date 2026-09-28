<?php

namespace App\Support;

use App\Support\Performance\PerformancePeriod;
use Illuminate\Support\Carbon;

/**
 * The panel-wide "which period am I looking at" setting, set by the topbar
 * period selector (resources/views/filament/components/global-month-selector.blade.php)
 * and read here on every request.
 *
 * Two plain cookies, written client-side and read server-side (the same
 * pattern AdminPanelProvider::activeTheme() uses for the theme switcher,
 * so no session or database state is needed for it to apply panel-wide):
 *
 *  - `selected_month` ("Y-m"): the calendar month, as it always was;
 *  - `selected_period` (added 2026-09-27): the mode — "month" (default),
 *    "fy:2026" (the financial year April 2026 – March 2027) or "all".
 *
 * range() / label() follow the mode, so every listing filter and widget
 * that scopes by the topbar range covers a whole financial year or all
 * time when those are picked. current() always answers with ONE calendar
 * month for the callers that are monthly by nature (targets, incentives,
 * calendars): the selected month, or the month of the period that today
 * falls in (its last month for a past period, its first for a future one).
 */
class SelectedMonth
{
    public const COOKIE = 'selected_month';

    public const PERIOD_COOKIE = 'selected_period';

    public const MODE_MONTH = 'month';

    public const MODE_FINANCIAL_YEAR = 'fy';

    public const MODE_ALL = 'all';

    /** The Indian financial year starts in April. */
    public const FINANCIAL_YEAR_START_MONTH = 4;

    /** The earliest date "all time" reaches back to. */
    public const ALL_TIME_START_YEAR = 2000;

    /**
     * "month", "fy" or "all".
     */
    public static function mode(): string
    {
        $value = (string) request()->cookie(self::PERIOD_COOKIE);

        if ($value === self::MODE_ALL) {
            return self::MODE_ALL;
        }

        if (preg_match('/^fy:(\d{4})$/', $value)) {
            return self::MODE_FINANCIAL_YEAR;
        }

        return self::MODE_MONTH;
    }

    public static function isMonthMode(): bool
    {
        return self::mode() === self::MODE_MONTH;
    }

    /**
     * The calendar month picked in the topbar (the `selected_month` cookie),
     * regardless of mode.
     */
    public static function selectedMonth(): Carbon
    {
        $value = request()->cookie(self::COOKIE);

        if ($value) {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (\Exception) {
                // Malformed cookie value — fall through to "this month".
            }
        }

        return now()->startOfMonth();
    }

    /**
     * The one calendar month monthly callers work with: the selected month,
     * or — for a financial year / all time — the month today falls in
     * inside that period, its last month when the period has passed, its
     * first when it has not started.
     */
    public static function current(): Carbon
    {
        if (self::isMonthMode()) {
            return self::selectedMonth();
        }

        [$start, $end] = self::range();
        $today = now()->startOfMonth();

        if ($today->lt($start)) {
            return $start->copy()->startOfMonth();
        }

        if ($today->gt($end)) {
            return $end->copy()->startOfMonth();
        }

        return $today;
    }

    /**
     * The start year of the selected financial year (2026 for FY 2026-27),
     * or of the financial year today falls in outside FY mode.
     */
    public static function financialYearStart(): int
    {
        if (preg_match('/^fy:(\d{4})$/', (string) request()->cookie(self::PERIOD_COOKIE), $matches)) {
            return (int) $matches[1];
        }

        return self::financialYearStartFor(now());
    }

    public static function financialYearStartFor(Carbon $date): int
    {
        return $date->month >= self::FINANCIAL_YEAR_START_MONTH ? (int) $date->year : (int) $date->year - 1;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function financialYearRange(int $startYear): array
    {
        $start = Carbon::create($startYear, self::FINANCIAL_YEAR_START_MONTH, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(): array
    {
        return match (self::mode()) {
            self::MODE_ALL => [
                Carbon::create(self::ALL_TIME_START_YEAR, 1, 1)->startOfDay(),
                now()->addYears(5)->endOfYear(),
            ],
            self::MODE_FINANCIAL_YEAR => self::financialYearRange(self::financialYearStart()),
            default => PerformancePeriod::range(PerformancePeriod::MONTHLY, self::selectedMonth()),
        };
    }

    /**
     * Whether today falls inside the selected period (for a month: whether
     * it is this calendar month). Widgets use it to decide between "so far"
     * and "whole period" arithmetic.
     */
    public static function isCurrentCalendarMonth(): bool
    {
        if (self::isMonthMode()) {
            return self::selectedMonth()->isSameMonth(now());
        }

        [$start, $end] = self::range();

        return now()->between($start, $end);
    }

    /**
     * Days of the period that have passed, counting today: the whole period
     * once it is over, at least 1 before it starts.
     */
    public static function elapsedDays(): int
    {
        [$start, $end] = self::range();

        if (now()->lt($start)) {
            return 1;
        }

        $until = now()->lt($end) ? now() : $end;

        return max(1, (int) $start->copy()->startOfDay()->diffInDays($until->copy()->startOfDay()) + 1);
    }

    /**
     * Days of the period still to come, counting today: 0 once it is over,
     * the whole period before it starts.
     */
    public static function remainingDays(): int
    {
        [$start, $end] = self::range();

        if (now()->gt($end)) {
            return 0;
        }

        $from = now()->gt($start) ? now() : $start;

        return max(0, (int) $from->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
    }

    public static function label(): string
    {
        return match (self::mode()) {
            self::MODE_ALL => 'All time',
            self::MODE_FINANCIAL_YEAR => self::financialYearLabel(self::financialYearStart()),
            default => PerformancePeriod::label(PerformancePeriod::MONTHLY, self::selectedMonth()),
        };
    }

    /** "FY 2026-27" */
    public static function financialYearLabel(int $startYear): string
    {
        return 'FY '.$startYear.'-'.substr((string) ($startYear + 1), -2);
    }

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_MONTH => 'Month',
            self::MODE_FINANCIAL_YEAR => 'Financial year',
            self::MODE_ALL => 'All time',
        ];
    }

    /**
     * @return array<int, string> start year => "FY 2026-27"
     */
    public static function financialYearOptions(): array
    {
        $years = self::yearOptions();
        $options = [];

        foreach (range(min($years) - 1, max($years)) as $startYear) {
            $options[$startYear] = self::financialYearLabel($startYear);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        return [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];
    }

    /**
     * @return array<int, int>
     */
    public static function yearOptions(): array
    {
        $currentYear = (int) now()->year;

        return range($currentYear - 3, $currentYear + 1);
    }
}
