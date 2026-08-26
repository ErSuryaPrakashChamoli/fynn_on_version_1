<?php

namespace App\Services;

use App\Models\Employee;

class IncentiveCalculator
{
    /**
     * Calculate incentive/performance for an employee.
     *
     * IMPORTANT:
     * The calculation is based on the employee passed to this method,
     * NOT on the role of the currently logged-in user.
     *
     * Therefore:
     *
     * Admin viewing Caller A
     *     -> Caller A performance
     *
     * Admin viewing Manager A
     *     -> Manager A hierarchy performance
     *
     * Admin employee record
     *     -> Company-wide performance
     */
    public static function calculate(?Employee $employee): array
    {
        /*
        |--------------------------------------------------------------------------
        | Use the central calculation engine
        |--------------------------------------------------------------------------
        |
        | A null $employee is a valid input meaning "company-wide view" —
        | getPerformance() already handles it directly. Do not special-case
        | it here; that previously short-circuited to an all-zero result
        | instead of the true company-wide total.
        |
        */

        $calculator = app(AchievementCalculatorService::class);

        $performance = $calculator->getPerformance($employee);

        /*
        |--------------------------------------------------------------------------
        | Return normalized result
        |--------------------------------------------------------------------------
        */

        return [
            'target_category' => $performance['target_category'] ?? null,
            'target' => (float) ($performance['target'] ?? 0),
            'actual' => (float) ($performance['actual'] ?? 0),
            'cashback' => (float) ($performance['cashback'] ?? 0),
            'subvention' => (float) ($performance['subvention'] ?? 0),
            'docking' => (float) ($performance['docking'] ?? 0),
            'count_achievement' => (float) ($performance['count_achievement'] ?? 0),
            'incentive' => (float) ($performance['incentive'] ?? 0),
        ];
    }
}
