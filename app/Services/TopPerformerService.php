<?php

namespace App\Services;

use App\Models\Employee;

class TopPerformerService
{
    public function __construct(
        protected AchievementCalculatorService $calculator
    ) {}

    public function getTopPerformers(?Employee $loggedInEmployee): array
    {
        // If Admin (no employee record), show Top 5 Callers
        if (!$loggedInEmployee) {
            $designation = Employee::DESIGNATION_CALLER;
            $limit = 5;
        } else {
            $designation = $loggedInEmployee->designation;

            $limit = $designation == Employee::DESIGNATION_CALLER
                ? 5
                : 3;
        }

        $employees = Employee::where('designation', $designation)
            ->where('exit_status', 'no')
            ->get();

        $performers = [];

        foreach ($employees as $employee) {

            $performers[] = [
                'name' => $employee->emp_name,
                'target' => $this->calculator->getTarget($employee),
                'countAchievement' => $this->calculator->getCountAchievement($employee),
                'percentage' => $this->calculator->getPercentage($employee),
            ];
        }

        usort($performers, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);

        return array_slice($performers, 0, $limit);
    }
}
