<?php

namespace App\Support;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The slice of a month somebody is on the rolls for — from their reporting
 * date to their exit date. The one place this is decided: a caller's
 * month target (AchievementCalculatorService) and the headcount a Team
 * Leader's callers and top-up count from (ReportingTimeline) both read it.
 */
final class OnRollsWindow
{
    /**
     * The slice of [$monthStart, $monthEnd] $employee is on the rolls for.
     *
     * Returns null when they are not on the rolls at all that month (left
     * before it began, or — for a month that has already fully elapsed —
     * had not joined yet).
     *
     * The end of the window is where the business rule lives, and it
     * changed on 2026-09-10. It used to be capped at TODAY, which meant a
     * mid-month joiner contributed a 0 target to their Team Leader's
     * rollup until ten days had physically passed: a caller who reported
     * on the 7th showed nothing on the 10th, and the Team Leader had no
     * way to tell an unset target from a genuinely nil one. The window now
     * runs to month-end for anyone still on the rolls — i.e. the target is
     * assigned on the basis that they will stay for the rest of the month.
     *
     * That projection is self-correcting rather than optimistic: the
     * moment an exit date is recorded, the window ends there instead, and
     * a caller who leaves before completing the ten-day minimum drops back
     * to a 0 target on the next evaluation. Nothing is cached, so no
     * recalculation job is needed.
     *
     * Counting is by CALENDAR days, deliberately: it is what the rule has
     * always used, and moving to working days here would silently restate
     * the targets of months that are already closed.
     *
     * @return array{start: Carbon, end: Carbon, days: int, is_partial: bool, is_projected: bool}|null
     */
    public static function forMonth(Employee $employee, CarbonInterface $monthStart, CarbonInterface $monthEnd): ?array
    {
        $monthStart = Carbon::parse($monthStart->toDateString())->startOfDay();
        $monthEnd = Carbon::parse($monthEnd->toDateString())->startOfDay();
        $today = Carbon::today();

        $exitDate = self::recordedExitDate($employee);

        // Left before this month ever started.
        if ($exitDate !== null && $exitDate->lt($monthStart)) {
            return null;
        }

        $reportingDate = filled($employee->reporting_date)
            ? Carbon::parse($employee->reporting_date)->startOfDay()
            : null;

        /*
         * Reporting date after the month ends. Only meaningful for a month
         * that has already fully elapsed; for the current month it cannot
         * be told apart from a future-dated data-entry slip, so behaviour
         * there is left as it was — fall through to the category target.
         */
        if ($reportingDate !== null && $reportingDate->gt($monthEnd) && $monthEnd->lt($today)) {
            return null;
        }

        $joinedThisMonth = $reportingDate !== null
            && $reportingDate->betweenIncluded($monthStart, $monthEnd);

        $leftThisMonth = $exitDate !== null
            && $exitDate->betweenIncluded($monthStart, $monthEnd);

        $start = $joinedThisMonth ? $reportingDate->copy() : $monthStart->copy();
        $end = $leftThisMonth ? $exitDate->copy() : $monthEnd->copy();

        // Exit recorded before the reporting date — a data anomaly, not a
        // working window.
        if ($end->lt($start)) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => (int) $start->diffInDays($end) + 1,
            'is_partial' => $joinedThisMonth || $leftThisMonth,
            // True while the window's end is still in the future — the
            // figure rests on the caller staying to month-end.
            'is_projected' => ! $leftThisMonth && $end->gt($today),
        ];
    }

    /**
     * The exit date to honour, or null if the employee is on the rolls.
     *
     * An 'yes' exit status with no date recorded is treated as still
     * active — there is no day to end the window on, and guessing one
     * would silently zero a target.
     */
    private static function recordedExitDate(Employee $employee): ?Carbon
    {
        if (strtolower((string) $employee->exit_status) !== 'yes' || empty($employee->exit_date)) {
            return null;
        }

        return Carbon::parse($employee->exit_date)->startOfDay();
    }
}
