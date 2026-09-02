<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TopPerformerService
{
    public function __construct(
        protected AchievementCalculatorService $calculator
    ) {}

    /**
     * The month these figures actually belong to — may be the prior month
     * rather than the real current one, see
     * AchievementCalculatorService::resolveReferenceMonth().
     */
    public function getReferenceMonth(): Carbon
    {
        return $this->calculator->resolveReferenceMonth();
    }

    public function getTopPerformers(?Employee $loggedInEmployee): array
    {
        // Admin (no employee record) sees a combined leaderboard: Top 5
        // Callers, Top 5 Team Leaders, and Top 2 Managers, in that order.
        if (! $loggedInEmployee) {
            return [
                ...$this->getTopPerformersForDesignation(Employee::DESIGNATION_CALLER, 5),
                ...$this->getTopPerformersForDesignation(Employee::DESIGNATION_TEAM_LEADER, 5),
                ...$this->getTopPerformersForDesignation(Employee::DESIGNATION_MANAGER, 2),
            ];
        }

        $designation = $loggedInEmployee->designation;

        $limit = $designation == Employee::DESIGNATION_CALLER
            ? 5
            : 3;

        return $this->getTopPerformersForDesignation($designation, $limit);
    }

    /**
     * @return array<int, array{name: string, designation: int, target: float, countAchievement: float, percentage: float}>
     */
    private function getTopPerformersForDesignation(int $designation, int $limit): array
    {
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

                $performers = $designation === Employee::DESIGNATION_CALLER
                    ? $this->buildCallerPerformers($employees)
                    : $this->buildHierarchyPerformers($employees, $designation);

                usort($performers, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);

                return $performers;
            }
        );

        return array_slice($performers, 0, $limit);
    }

    /**
     * A Caller's target is a flat category value (no query) and their
     * achievement is their own customers only (no hierarchy fan-out), so
     * the whole group's achievement can be fetched in a single batched
     * query instead of one query per caller — this is the largest employee
     * group, so it's the one worth batching.
     *
     * @param  Collection<int, Employee>  $callers
     * @return array<int, array{name: string, designation: int, target: float, countAchievement: float, percentage: float}>
     */
    private function buildCallerPerformers($callers): array
    {
        $achievementByEmployeeId = $this->calculator->countAchievementByEmployeeId(
            $callers->pluck('id')
        );

        return $callers->map(function (Employee $caller) use ($achievementByEmployeeId) {
            $target = $this->calculator->getTarget($caller);
            $achievement = $achievementByEmployeeId[$caller->id] ?? 0.0;
            $percentage = $this->calculator->percentageFromAmounts($achievement, $target);

            return [
                'name' => $caller->emp_name,
                'designation' => Employee::DESIGNATION_CALLER,
                'target' => $target,
                'countAchievement' => $achievement,
                'percentage' => $percentage,
            ];
        })->all();
    }

    /**
     * Team Leaders/Managers/Clusters each roll up a different subordinate
     * tree of customers under their own id — that can't be produced by a
     * flat GROUP BY, so these still go through the canonical per-employee
     * engine.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{name: string, designation: int, target: float, countAchievement: float, percentage: float}>
     */
    private function buildHierarchyPerformers($employees, int $designation): array
    {
        $performers = [];

        foreach ($employees as $employee) {
            $target = $this->calculator->getTarget($employee);
            $achievement = $this->calculator->getCountAchievement($employee);
            $percentage = $this->calculator->percentageFromAmounts($achievement, $target);

            $performers[] = [
                'name' => $employee->emp_name,
                'designation' => $designation,
                'target' => $target,
                'countAchievement' => $achievement,
                'percentage' => $percentage,
            ];
        }

        return $performers;
    }
}
