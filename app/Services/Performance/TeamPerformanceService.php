<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\UserLoginSession;
use App\Support\HierarchyHelper;
use App\Support\Performance\PerformancePeriod;
use App\Support\ReportingTimeline;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Team-level rollups that don't belong on a single employee's card:
 * attrition/retention and per-member + aggregate attendance for a
 * team lead's subordinate tree (the lead themselves is excluded from
 * their own team's numbers).
 *
 * The team is the one of the period, day by day (ReportingTimeline): a
 * member who moved in or out counts only for the days they were in it.
 */
class TeamPerformanceService
{
    public function attrition(?Employee $teamLead, Carbon $start, Carbon $end): array
    {
        // Everyone in the team on any day of the period; on the first day,
        // only those in it then make up the opening headcount.
        $spans = $teamLead
            ? ReportingTimeline::between($start, $end)->branchSpans($teamLead->id, includeBoss: false)
            : null;

        $memberIds = $spans !== null
            ? collect(array_keys($spans))
            : Employee::query()->where('designation', '!=', Employee::DESIGNATION_ADMIN)->pluck('id');

        $members = Employee::query()->whereIn('id', $memberIds)->get();

        $headcountStart = $members->filter(function (Employee $employee) use ($start, $spans) {
            if ($spans !== null && ReportingTimeline::overlapDays($spans[$employee->id] ?? [], $start, $start) === 0) {
                return false;
            }

            $joined = $employee->doj ? Carbon::parse($employee->doj) : null;
            $exited = $employee->exit_status === 'yes' && $employee->exit_date
                ? Carbon::parse($employee->exit_date)
                : null;

            return (! $joined || $joined->lt($start)) && (! $exited || $exited->gte($start));
        })->count();

        $exits = $members->filter(function (Employee $employee) use ($start, $end) {
            if ($employee->exit_status !== 'yes' || ! $employee->exit_date) {
                return false;
            }

            return Carbon::parse($employee->exit_date)->between($start, $end);
        })->count();

        $joins = $members->filter(function (Employee $employee) use ($start, $end) {
            return $employee->doj && Carbon::parse($employee->doj)->between($start, $end);
        })->count();

        $headcountEnd = max($headcountStart - $exits + $joins, 0);
        $avgHeadcount = ($headcountStart + $headcountEnd) / 2;

        $attritionRate = $avgHeadcount > 0 ? round(($exits / $avgHeadcount) * 100, 1) : 0.0;

        return [
            'headcount_start' => $headcountStart,
            'headcount_end' => $headcountEnd,
            'exits' => $exits,
            'joins' => $joins,
            'attrition_rate' => $attritionRate,
            'retention_rate' => round(100 - $attritionRate, 1),
        ];
    }

    /**
     * @return array{members: Collection, team_attendance_rate: float}
     */
    public function attendance(Employee $teamLead, Carbon $start, Carbon $end): array
    {
        $memberIds = HierarchyHelper::subordinateIdsDuring($teamLead, $start, $end)
            ->reject(fn ($id) => $id === $teamLead->id)
            ->values();

        $members = Employee::query()->whereIn('id', $memberIds)->get()->keyBy('id');

        // Only the days each member was in this team count here.
        $byEmployee = HierarchyHelper::scopeToBranchDuring(
            UserLoginSession::query(),
            $teamLead,
            $start,
            $end,
            'employee_id',
            'login_at',
        )
            ->whereIn('employee_id', $memberIds)
            ->get(['employee_id', 'login_at'])
            ->groupBy('employee_id');

        $workingDays = PerformancePeriod::workingDays($start, $end);

        $rows = $memberIds->map(function ($id) use ($members, $byEmployee, $workingDays) {
            $presentDays = $byEmployee->get($id, collect())
                ->pluck('login_at')
                ->map->toDateString()
                ->unique()
                ->count();

            return [
                'employee' => $members->get($id),
                'present_days' => $presentDays,
                'working_days' => $workingDays,
                'attendance_rate' => $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 1) : 0.0,
            ];
        })->values();

        $totalPresent = $rows->sum('present_days');
        $totalSlots = $workingDays * max($rows->count(), 1);

        return [
            'members' => $rows,
            'team_attendance_rate' => $totalSlots > 0 ? round(($totalPresent / $totalSlots) * 100, 1) : 0.0,
        ];
    }
}
