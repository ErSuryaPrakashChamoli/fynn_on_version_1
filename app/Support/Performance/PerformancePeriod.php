<?php

namespace App\Support\Performance;

use Carbon\Carbon;

/**
 * Resolves calendar ranges for the reporting cadences the Performance
 * module supports, and generates the trailing-period sequences used for
 * trend sparklines.
 */
class PerformancePeriod
{
    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const HALF_YEARLY = 'half_yearly';

    public const YEARLY = 'yearly';

    public const CUSTOM = 'custom';

    public static function options(): array
    {
        return [
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::HALF_YEARLY => 'Half-Yearly',
            self::YEARLY => 'Yearly',
            self::CUSTOM => 'Custom Range',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} start/end of the period of $type containing $reference.
     *                                     For $type === self::CUSTOM, $customStart/$customEnd are
     *                                     used verbatim instead of being derived from $reference.
     */
    public static function range(string $type, Carbon $reference, ?Carbon $customStart = null, ?Carbon $customEnd = null): array
    {
        if ($type === self::CUSTOM) {
            return [
                ($customStart ?? $reference)->copy()->startOfDay(),
                ($customEnd ?? $customStart ?? $reference)->copy()->endOfDay(),
            ];
        }

        return match ($type) {
            self::WEEKLY => [$reference->copy()->startOfWeek(), $reference->copy()->endOfWeek()],
            self::QUARTERLY => [$reference->copy()->startOfQuarter(), $reference->copy()->endOfQuarter()],
            self::HALF_YEARLY => self::halfYearRange($reference),
            self::YEARLY => [$reference->copy()->startOfYear(), $reference->copy()->endOfYear()],
            default => [$reference->copy()->startOfMonth(), $reference->copy()->endOfMonth()],
        };
    }

    private static function halfYearRange(Carbon $reference): array
    {
        if ($reference->month <= 6) {
            return [
                $reference->copy()->startOfYear(),
                $reference->copy()->startOfYear()->addMonths(5)->endOfMonth(),
            ];
        }

        return [
            $reference->copy()->startOfYear()->addMonths(6),
            $reference->copy()->endOfYear(),
        ];
    }

    public static function shift(string $type, Carbon $reference, int $amount): Carbon
    {
        return match ($type) {
            self::WEEKLY => $reference->copy()->addWeeks($amount),
            self::QUARTERLY => $reference->copy()->addMonthsNoOverflow($amount * 3),
            self::HALF_YEARLY => $reference->copy()->addMonthsNoOverflow($amount * 6),
            self::YEARLY => $reference->copy()->addYears($amount),
            default => $reference->copy()->addMonthsNoOverflow($amount),
        };
    }

    public static function label(string $type, Carbon $start): string
    {
        return match ($type) {
            self::WEEKLY => 'Wk of '.$start->format('d M Y'),
            self::QUARTERLY => 'Q'.$start->quarter.' '.$start->year,
            self::HALF_YEARLY => ($start->month <= 6 ? 'H1' : 'H2').' '.$start->year,
            self::YEARLY => (string) $start->year,
            default => $start->format('M Y'),
        };
    }

    /**
     * The last $count periods of $type, ending with the period containing
     * $reference. Oldest first — ready to feed directly into a trend chart.
     *
     * @return array<int, array{start: Carbon, end: Carbon, label: string}>
     */
    public static function trailing(string $type, Carbon $reference, int $count = 6): array
    {
        if ($type === self::CUSTOM) {
            return [];
        }

        $periods = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $ref = self::shift($type, $reference->copy(), -$i);
            [$start, $end] = self::range($type, $ref);

            $periods[] = [
                'start' => $start,
                'end' => $end,
                'label' => self::label($type, $start),
            ];
        }

        return $periods;
    }

    /**
     * Approximate number of calendar months spanned — used only to prorate
     * the (inherently monthly) target amount across non-monthly periods.
     */
    public static function monthSpan(Carbon $start, Carbon $end): float
    {
        return max($start->diffInDays($end) / 30.44, 7 / 30.44);
    }

    /**
     * Calendar working days in [$start, $end], per config('performance.working_days_exclude').
     */
    public static function workingDays(Carbon $start, Carbon $end): int
    {
        $excluded = config('performance.working_days_exclude', [0]);

        $count = 0;
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            if (! in_array($cursor->dayOfWeek, $excluded, true)) {
                $count++;
            }

            $cursor->addDay();
        }

        return $count;
    }
}
