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
        | No employee
        |--------------------------------------------------------------------------
        */

        if (! $employee) {
            return self::emptyResult();
        }

        /*
        |--------------------------------------------------------------------------
        | Use the central calculation engine
        |--------------------------------------------------------------------------
        */

        $calculator = app(AchievementCalculatorService::class);

        $performance = $calculator->getPerformance($employee);

        /*
        |--------------------------------------------------------------------------
        | Return normalized result
        |--------------------------------------------------------------------------
        */

        return [
            'target_category'   => $performance['target_category'] ?? null,
            'target'            => (float) ($performance['target'] ?? 0),
            'actual'            => (float) ($performance['actual'] ?? 0),
            'cashback'          => (float) ($performance['cashback'] ?? 0),
            'subvention'        => (float) ($performance['subvention'] ?? 0),
            'docking'           => (float) ($performance['docking'] ?? 0),
            'count_achievement' => (float) ($performance['count_achievement'] ?? 0),
            'incentive'         => (float) ($performance['incentive'] ?? 0),
        ];
    }

    /**
     * Empty performance result.
     */
    protected static function emptyResult(): array
    {
        return [
            'target_category'   => null,
            'target'            => 0,
            'actual'            => 0,
            'cashback'          => 0,
            'subvention'        => 0,
            'docking'           => 0,
            'count_achievement' => 0,
            'incentive'         => 0,
        ];
    }
}
