<?php

namespace App\Support;

use App\Support\Performance\PerformancePeriod;
use Illuminate\Support\Carbon;

/**
 * The panel-wide "which month am I looking at" setting. Set by the topbar
 * month selector (see resources/views/filament/components/global-month-selector.blade.php)
 * as a plain `selected_month` cookie ("Y-m") and read here on every
 * request — the same client-set-cookie/read-server-side pattern
 * AdminPanelProvider::activeTheme() already uses for the theme switcher, so
 * no session or database state is needed for it to apply panel-wide.
 */
class SelectedMonth
{
    public const COOKIE = 'selected_month';

    public static function current(): Carbon
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
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(): array
    {
        return PerformancePeriod::range(PerformancePeriod::MONTHLY, self::current());
    }

    public static function isCurrentCalendarMonth(): bool
    {
        return self::current()->isSameMonth(now());
    }

    public static function label(): string
    {
        return PerformancePeriod::label(PerformancePeriod::MONTHLY, self::current());
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
