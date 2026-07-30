<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Customer;

class IncentiveCalculator
{
    public static function calculate(Employee $employee): array
    {
        $customers = Customer::query()
            ->where('employee_id', $employee->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $actual = (float) $customers->sum('sanctioned_loan_amount');
        $cashback = (float) $customers->sum('cashback');
        $subvention = (float) $customers->sum('subvention');
        $docking = (float) $customers->sum('docking');

        $countAchievement = $actual - (
            (($cashback + $subvention + $docking) / 2) * 100
        );

        $target = self::target($employee);

        return [
            'target_category' => 'Monthly',
            'target' => $target,
            'actual' => $actual,
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => $docking,
            'count_achievement' => $countAchievement,
            'incentive' => self::calculateIncentive($countAchievement),
        ];
    }

    protected static function target(Employee $employee): float
    {
        return 1500000;
    }

    protected static function calculateIncentive(float $achievement): float
    {
        // your slab logic here
        return 0;
    }
}
