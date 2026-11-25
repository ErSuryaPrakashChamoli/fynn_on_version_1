<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HierarchyTransferLog;
use App\Models\User;
use App\Support\EmployeeOptions;
use App\Support\ReportingTree;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves employees to a new boss — from "Transfer Employee" (Employee page,
 * Employee Lookup), "Move Team" and the Flexible Reassignment.
 *
 * They take all their own work with them — customers, leads and assigned
 * leads, whoever created or gave them — seen by the new boss from the
 * effective date; nothing is handed over while they stay on the rolls
 * (user decision 2026-11-18). Delegations run on. Past months stay with the
 * old team (ReportingTimeline reads the dated history rows).
 *
 * Their team goes wherever the admin says (user decision 2026-11-24): the
 * whole team along, part of it along, or nobody — whoever stays behind is
 * given a new boss in the same step and keeps their own work. Everybody who
 * moves takes everyone below them. "Move Team" is the other way round: the
 * boss stays and all or part of their team moves to somebody else.
 */
class EmployeeTransferService
{
    /** Their whole team moves with them. */
    public const TEAM_WHOLE = 'whole';

    /** The direct reports picked move with them; the rest stay behind. */
    public const TEAM_PART = 'part';

    /** They move alone; their whole team stays behind. */
    public const TEAM_NONE = 'none';

    public function __construct(
        private EmployeeDependencyService $dependencies,
        private ReportingLineService $reportingLines,
        private HierarchyReassignmentService $reassignments,
    ) {}

    /**
     * The team choices, as a form shows them.
     *
     * @return array<string, string>
     */
    public static function teamChoices(): array
    {
        return [
            self::TEAM_WHOLE => 'Whole team moves with them',
            self::TEAM_PART => 'Part of the team moves',
            self::TEAM_NONE => 'Move alone',
        ];
    }

    /**
     * The people reporting straight to $employee, as checklist options:
     * "Asha Rao (FA0101)" => "Team Leader · 6 below them".
     *
     * @return array{options: array<int, string>, descriptions: array<int, string>}
     */
    public function teamChecklist(Employee $employee): array
    {
        $tree = ReportingTree::load();
        $positions = Employee::designationOptions();

        $team = $this->dependencies->directReports($employee)
            ->get(['id', 'emp_name', 'emp_id', 'designation'])
            ->sortBy([
                fn (Employee $a, Employee $b): int => Employee::designationRank($b->designation) <=> Employee::designationRank($a->designation),
                fn (Employee $a, Employee $b): int => strcasecmp((string) $a->emp_name, (string) $b->emp_name),
            ]);

        return [
            'options' => $team->mapWithKeys(fn (Employee $report): array => [$report->id => EmployeeOptions::label($report)])->all(),
            'descriptions' => $team->mapWithKeys(function (Employee $report) use ($tree, $positions): array {
                $below = count(array_filter($tree->descendantIds($report->id), fn (int $id): bool => ! $tree->isExited($id)));

                return [$report->id => ($positions[$report->designation] ?? 'Employee')
                    .($below > 0 ? ' · '.$below.' '.Str::plural('person', $below).' below them' : '')];
            })->all(),
        ];
    }

    /**
     * How many people sit below $employee, on the rolls — what a whole-team
     * move takes along.
     */
    public function peopleBelow(Employee $employee): int
    {
        $tree = ReportingTree::load();

        return count(array_filter($tree->descendantIds($employee->id), fn (int $id): bool => ! $tree->isExited($id)));
    }

    /**
     * The direct reports a team choice takes along.
     *
     * @param  array<int|string>  $picked  the checklist, for TEAM_PART
     * @return list<int>
     */
    public function comingAlongIds(Employee $employee, ?string $team, array $picked = []): array
    {
        $teamIds = $this->dependencies->directReports($employee)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return match ($team) {
            self::TEAM_WHOLE => array_values($teamIds),
            self::TEAM_PART => array_values(array_intersect($teamIds, array_map('intval', $picked))),
            default => [],
        };
    }

    /**
     * Who the part of $employee's team that stays behind may report to:
     * anyone on the rolls senior to all of them — never $employee nor
     * anybody below them, who are all moving away. Grouped by level.
     *
     * @param  list<int>  $comingAlongIds
     * @return array<string, array<int, string>>
     */
    public function stayingBehindBossOptions(Employee $employee, array $comingAlongIds): array
    {
        return $this->withoutIds(
            $this->dependencies->newBossOptions($employee, $comingAlongIds),
            ReportingTree::load()->descendantIds($employee->id),
        );
    }

    /**
     * Move $employee, with their own work, to $newBossId from $effectiveDate.
     *
     * $comingAlongIds are the direct reports who move with them (each with
     * everyone below). Anybody else reporting to them is moved to
     * $stayingBehindBossId first, dated the same day; with nobody chosen
     * for them the move is refused.
     *
     * @param  list<int>  $comingAlongIds
     *
     * @throws ValidationException while somebody staying behind has no new
     *                             boss, the date is in the future, or a
     *                             boss is not allowed
     */
    public function transfer(
        Employee $employee,
        int $newBossId,
        CarbonInterface $effectiveDate,
        ?string $remarks,
        ?User $performedBy,
        array $comingAlongIds = [],
        ?int $stayingBehindBossId = null,
    ): void {
        if ($effectiveDate->copy()->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages(['effective_date' => 'The effective date cannot be in the future.']);
        }

        DB::transaction(function () use ($employee, $newBossId, $effectiveDate, $remarks, $performedBy, $comingAlongIds, $stayingBehindBossId): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $teamIds = $this->dependencies->directReports($employee)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $comingAlongIds = array_values(array_unique(array_map('intval', $comingAlongIds)));

            if (array_diff($comingAlongIds, $teamIds) !== []) {
                throw ValidationException::withMessages([
                    'coming_along' => "Somebody picked to move with {$employee->emp_name} no longer reports to them. Open the pop-up again and pick again.",
                ]);
            }

            $stayingBehindIds = array_values(array_diff($teamIds, $comingAlongIds));

            if ($stayingBehindIds !== []) {
                if ($stayingBehindBossId === null) {
                    $this->dependencies->assertNothingAttached($employee, 'reports_to', $comingAlongIds, EmployeeDependencyService::SCOPE_MOVE);
                }

                if ($stayingBehindBossId === $employee->id || in_array($stayingBehindBossId, ReportingTree::load()->descendantIds($employee->id), true)) {
                    throw ValidationException::withMessages([
                        'staying_team_boss' => "Those staying behind need a boss who is not moving with {$employee->emp_name}.",
                    ]);
                }

                $this->dependencies->moveDirectReports($employee, (int) $stayingBehindBossId, $effectiveDate, $performedBy, $comingAlongIds);
            }

            // reporting_date is deliberately left untouched (see
            // ReportingLineService): a transfer must not make them look like
            // a new joiner. The effective date dates the history entries.
            // reportTo() re-fills everyone still below them, so whoever
            // comes along follows.
            $this->reportingLines->reportTo(
                $employee,
                $newBossId,
                $effectiveDate,
                ReportingLineService::CHANGE_TRANSFER,
                filled($remarks) ? $remarks : $this->defaultRemarks($teamIds, $comingAlongIds),
                $performedBy?->id,
            );
        });
    }

    /**
     * "Move Team": all of $boss's team, or the direct reports picked, move
     * to $newBossId while $boss stays where they are. Each takes everyone
     * below them and all their own work.
     *
     * @param  list<int>|null  $memberIds  null moves the whole team
     */
    public function moveTeam(Employee $boss, ?array $memberIds, int $newBossId, CarbonInterface|string $effectiveDate, ?string $remarks, int $performedBy): HierarchyTransferLog
    {
        return $this->reassignments->moveTeam($boss, $memberIds, $newBossId, $effectiveDate, $remarks, $performedBy);
    }

    /**
     * Who all of $memberIds (or $boss's whole team) may move to: anyone on
     * the rolls senior to every one of them, but $boss and anybody below
     * them. Grouped by level.
     *
     * @param  list<int>|null  $memberIds
     * @return array<string, array<int, string>>
     */
    public function moveTeamTargetOptions(Employee $boss, ?array $memberIds): array
    {
        $tree = ReportingTree::load();
        $memberIds = $memberIds === null
            ? $this->dependencies->directReports($boss)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : array_values(array_intersect(array_map('intval', $memberIds), $tree->childIds($boss->id)));

        $mostSenior = collect($memberIds)
            ->sortByDesc(fn (int $id): int => Employee::designationRank($tree->designation($id)))
            ->first();

        if ($mostSenior === null) {
            return [];
        }

        return $this->withoutIds(
            $this->reportingLines->bossOptions($tree->designation($mostSenior), $boss->id),
            $tree->descendantIds($boss->id),
        );
    }

    /**
     * A Flexible Reassignment, refused while anybody in it whose team stays
     * behind still has somebody reporting to them (SCOPE_MOVE). A row whose
     * 'team' is TEAM_WHOLE takes everyone below along. Rows run lowest
     * level first, so a caller moved away from their Team Leader in the
     * same batch has gone before the Team Leader moves.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null, team?: string|null}>  $assignments
     */
    public function reassign(array $assignments, int $performedBy, ?string $effectiveDate = null, ?string $remarks = null): HierarchyTransferLog
    {
        return DB::transaction(function () use ($assignments, $performedBy, $effectiveDate, $remarks): HierarchyTransferLog {
            $blockers = $this->blockers($assignments);

            if ($blockers !== []) {
                $employeeId = array_key_first($blockers);
                $name = Employee::query()->whereKey($employeeId)->value('emp_name');

                throw ValidationException::withMessages([
                    'assignments' => "{$name} still has {$this->dependencies->describe($blockers[$employeeId])}. Hand them over to somebody else first, or let their team move with them.",
                ]);
            }

            $designations = Employee::query()
                ->whereIn('id', $this->movingIds($assignments))
                ->pluck('designation', 'id');

            $rows = collect($assignments)
                ->values()
                ->sortBy(fn (array $row): int => Employee::designationRank($designations[(int) ($row['employee_id'] ?? 0)] ?? null))
                ->values()
                ->all();

            return $this->reassignments->reassign($rows, $performedBy, $effectiveDate, $remarks);
        });
    }

    /**
     * Everybody in a Flexible Reassignment whose team stays behind and who
     * still has somebody reporting to them (SCOPE_MOVE) — direct reports
     * moving with the batch excepted — in the order they were entered.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null, team?: string|null}>  $assignments
     * @return array<int, array<string, int>> employee id => what is still attached
     */
    public function blockers(array $assignments): array
    {
        $teamMovesAlong = collect($assignments)
            ->filter(fn (array $row): bool => ($row['team'] ?? null) === self::TEAM_WHOLE)
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $blockers = [];

        foreach (Employee::query()->whereIn('id', $this->movingIds($assignments))->get()->keyBy('id') as $employeeId => $employee) {
            if (in_array($employeeId, $teamMovesAlong, true)) {
                continue;
            }

            $counts = $this->dependencies->moveDependencyCounts($employee, $this->alsoMovingIds($assignments, $employeeId));

            if ($counts !== []) {
                $blockers[$employeeId] = $counts;
            }
        }

        return collect($this->movingIds($assignments))
            ->filter(fn (int $id): bool => isset($blockers[$id]))
            ->mapWithKeys(fn (int $id): array => [$id => $blockers[$id]])
            ->all();
    }

    /**
     * The people the batch moves away from $employeeId — their direct
     * reports among them are not left behind.
     *
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     * @return list<int>
     */
    public function alsoMovingIds(array $assignments, int $employeeId): array
    {
        return collect($assignments)
            ->filter(fn (array $row): bool => filled($row['employee_id'] ?? null)
                && filled($row['target_id'] ?? null)
                && (int) $row['target_id'] !== $employeeId)
            ->map(fn (array $row): int => (int) $row['employee_id'])
            ->reject(fn (int $id): bool => $id === $employeeId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, array{employee_id?: int|string|null, target_id?: int|string|null}>  $assignments
     * @return list<int>
     */
    private function movingIds(array $assignments): array
    {
        return collect($assignments)
            ->pluck('employee_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $teamIds
     * @param  list<int>  $comingAlongIds
     */
    private function defaultRemarks(array $teamIds, array $comingAlongIds): string
    {
        return match (true) {
            $teamIds === [] => 'Transferred.',
            count($comingAlongIds) === count($teamIds) => 'Transferred with their whole team.',
            $comingAlongIds === [] => 'Transferred alone; their team stays behind.',
            default => 'Transferred with part of their team.',
        };
    }

    /**
     * Grouped options without $ids, dropping groups left empty.
     *
     * @param  array<string, array<int, string>>  $options
     * @param  array<int, int>  $ids
     * @return array<string, array<int, string>>
     */
    private function withoutIds(array $options, array $ids): array
    {
        return collect($options)
            ->map(fn (array $group): array => array_diff_key($group, array_flip($ids)))
            ->filter()
            ->all();
    }
}
