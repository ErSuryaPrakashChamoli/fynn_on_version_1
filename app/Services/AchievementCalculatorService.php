<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use Carbon\Carbon;

class AchievementCalculatorService
{
    public function getCountAchievement(Employee $employee): float
    {
        $customers = Customer::where('employee_id', $employee->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $achievement = (clone $customers)->sum('sanctioned_loan_amount');
        $cashback = (clone $customers)->sum('cashback');
        $subvention = (clone $customers)->sum('subvention');
        $docking = (clone $customers)->sum('docking');

        return $achievement - ((($cashback + $subvention + $docking) / 2) * 100);
    }

    public function getTarget(Employee $employee): float
    {
        return is_numeric($employee->category)
            ? (float) $employee->category
            : 2500000;
    }

    public function getPercentage(Employee $employee): float
    {
        $target = $this->getTarget($employee);

        if ($target <= 0) {
            return 0;
        }

        return round(
            ($this->getCountAchievement($employee) / $target) * 100,
            2
        );
    }
}
