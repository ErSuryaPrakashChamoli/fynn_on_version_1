<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;

class TopPerformerService
{
    public function __construct(
        protected AchievementCalculatorService $calculator
    ) {}

    public function getTopPerformers(?Employee $loggedInEmployee): array
    {
        // If Admin (no employee record), show Top 5 Callers
        if (! $loggedInEmployee) {
            $designation = Employee::DESIGNATION_CALLER;
            $limit = 5;
        } else {
            $designation = $loggedInEmployee->designation;

            $limit = $designation == Employee::DESIGNATION_CALLER
                ? 5
                : 3;
        }

        // This widget is rendered on every admin page (topbar render hook)
        // and polled every 60s per open tab. Computing it involves a
        // per-employee achievement query, so it's cached rather than
        // recalculated on every request.
        $performers = Cache::remember(
            "top-performers:{$designation}",
            now()->addMinutes(5),
            function () use ($designation) {
                $employees = Employee::where('designation', $designation)
                    ->where('exit_status', 'no')
                    ->get();

                $performers = [];

                foreach ($employees as $employee) {
                    $target = $this->calculator->getTarget($employee);
                    $achievement = $this->calculator->getCountAchievement($employee);
                    $percentage = $this->calculator->percentageFromAmounts($achievement, $target);

                    $performers[] = [
                        'name' => $employee->emp_name,
                        'target' => $target,
                        'countAchievement' => $achievement,
                        'percentage' => $percentage,
                    ];
                }

                usort($performers, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);

                return $performers;
            }
        );

        return array_slice($performers, 0, $limit);
    }
}
