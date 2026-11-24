<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Position;
use App\Models\TargetCategory;
use App\Models\User;
use App\Support\ReportingTree;
use App\Support\TargetCategoryTimeline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotes, demotes or changes the target category of an employee on the
 * rolls — from the "Change Position / Target" action.
 *
 * Nothing they hold is handed over (user decision 2026-11-18): while they
 * stay on the rolls every customer, lead and assigned lead stays theirs,
 * and delegations run on. Their team is asked about only when the position
 * changes (user decision 2026-11-18): it may stay with them while the new
 * position is still senior to all of it (a promoted Team Leader keeps their
 * callers), or be given the new boss chosen in the same pop-up
 * ($teamBossId), in the same transaction — required when the new position
 * would no longer be senior to somebody in it. Either way the team keeps
 * its own work. A target category change alone touches nobody else.
 *
 * Both changes are dated: a designation change through
 * ReportingLineService::recordDesignationChange() and a category change
 * through ::recordCategoryChange(), so the months before the effective date
 * keep the designation and target held then (ReportingTimeline,
 * TargetCategoryTimeline) and the month it falls in is split on that day.
 */
class EmployeePositionChangeService
{
    public function __construct(
        private EmployeeDependencyService $dependencies,
        private ReportingLineService $reportingLines,
    ) {}

    /**
     * Give $employee $designation (reporting to $bossId) and $category from
     * $effectiveDate. $position, the designation title, is updated alongside
     * when given. When the position changes, anyone reporting to them
     * moves to $teamBossId from the same date, or stays with them when
     * $teamBossId is null and the new position is still senior to them all.
     *
     * @throws ValidationException when nothing would change, a value is not
     *                             offered, the boss (theirs or the team's) is
     *                             not allowed, their team has nobody to go to,
     *                             or the date is out of range
     */
    public function change(
        Employee $employee,
        int $designation,
        ?int $bossId,
        ?string $category,
        CarbonInterface $effectiveDate,
        ?string $remarks,
        ?User $performedBy,
        ?string $position = null,
        ?int $teamBossId = null,
    ): void {
        $category = filled($category) ? $category : null;
        $position = filled($position) ? $position : null;
        $effectiveDay = CarbonImmutable::parse($effectiveDate->toDateString());

        DB::transaction(function () use ($employee, $designation, $bossId, $category, $position, $effectiveDay, $remarks, $performedBy, $teamBossId): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $previousDesignation = (int) $employee->designation;
            $previousCategory = filled($employee->category) ? (string) $employee->category : null;
            $designationChanges = $designation !== $previousDesignation;
            $categoryChanges = $category !== $previousCategory;

            $this->assertChangeAllowed($employee, $designation, $category, $position, $designationChanges, $categoryChanges);
            $this->assertEffectiveDateAllowed($employee, $effectiveDay, $designationChanges, $categoryChanges);

            $bossId = $this->reportsToBoss($designation) ? $bossId : null;

            if ($designationChanges && ($problem = $this->reportingLines->bossProblem($designation, $bossId, $employee->id)) !== null) {
                $this->fail('reports_to', $problem);
            }

            $remarks = filled($remarks) ? trim($remarks) : $this->describe($previousDesignation, $designation, $previousCategory, $category);

            // A back-dated change sets their line from its effective date:
            // rows after it that kept the same people above them (re-slots
            // after a boss's designation change) would put the old position's
            // columns back from their own date.
            if ($designationChanges) {
                $this->reportingLines->dropSupersededLines($employee->id, $effectiveDay);
            }

            // Read before the position changes: the tree finds a boss only
            // through the column matching their position.
            $stayingTeamIds = $designationChanges
                ? $this->moveTeam($employee, $designation, $teamBossId, $effectiveDay, $performedBy)
                : [];

            foreach ($stayingTeamIds as $reportId) {
                $this->reportingLines->dropSupersededLines($reportId, $effectiveDay);
            }

            $employee->forceFill([
                'designation' => $designation,
                'category' => $category,
                ...($position !== null ? ['position' => $position] : []),
            ])->save();

            if ($designationChanges) {
                // A team that stays is put back under them at the new
                // position first, so their own move below carries it along.
                foreach (Employee::query()->whereIn('id', $stayingTeamIds)->get() as $report) {
                    $this->reportingLines->reportTo(
                        $report,
                        $employee->id,
                        $effectiveDay,
                        ReportingLineService::CHANGE_REPORTING,
                        "Follows {$employee->emp_name}'s designation change.",
                        $performedBy?->id,
                    );
                }

                // reporting_date is deliberately left untouched (see
                // ReportingLineService); the effective date dates the rows.
                $this->reportingLines->reportTo(
                    $employee,
                    $bossId,
                    $effectiveDay,
                    ReportingLineService::CHANGE_REPORTING,
                    $remarks,
                    $performedBy?->id,
                );

                $this->reportingLines->recordDesignationChange($employee, $previousDesignation, $effectiveDay, $remarks, $performedBy?->id);
            }

            if ($categoryChanges) {
                $this->reportingLines->recordCategoryChange($employee, $previousCategory, $effectiveDay, $remarks, $performedBy?->id);
            }

            foreach ([$employee->id, ...$stayingTeamIds] as $employeeId) {
                $this->reportingLines->resequenceHistory($employeeId);
            }
        });

        app(MonthlyTargetGate::class)->forget();
    }

    /**
     * The latest dated position or target change of $employee — the day it
     * took effect and every history row written with it: their own
     * designation, target category and reporting-line rows of that day, and
     * the rows of the team it re-slotted, handed over or re-filled — or
     * null when no position or target change was ever recorded for them.
     *
     * @return array{date: CarbonImmutable, rows: Collection<int, EmployeeReportingHistory>, designation: ?EmployeeReportingHistory, category: ?EmployeeReportingHistory}|null
     */
    public function lastChange(Employee $employee): ?array
    {
        $anchorDate = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->whereIn('change_type', [ReportingLineService::CHANGE_DESIGNATION, ReportingLineService::CHANGE_TARGET_CATEGORY])
            ->max('effective_date');

        if ($anchorDate === null) {
            return null;
        }

        $date = CarbonImmutable::parse($anchorDate)->startOfDay();

        $ownRows = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', '!=', 'exit')
            ->whereDate('effective_date', $date->toDateString())
            ->orderBy('id')
            ->get();

        $lineRemarks = $ownRows
            ->reject(fn (EmployeeReportingHistory $row): bool => in_array($row->change_type, [ReportingLineService::CHANGE_DESIGNATION, ReportingLineService::CHANGE_TARGET_CATEGORY], true))
            ->pluck('remarks')
            ->filter()
            ->all();

        $teamRemarks = [
            "Follows {$employee->emp_name}'s designation change.",
            "Handed over from {$employee->emp_name}.",
        ];

        $teamRows = EmployeeReportingHistory::query()
            ->where('employee_id', '!=', $employee->id)
            ->whereNotIn('change_type', ReportingLineService::NON_REPORTING_CHANGES)
            ->whereDate('effective_date', $date->toDateString())
            ->whereIn('remarks', [...$teamRemarks, ...$lineRemarks])
            ->orderBy('id')
            ->get()
            // Re-filled below them by their own move: the row names them.
            ->filter(fn (EmployeeReportingHistory $row): bool => in_array($row->remarks, $teamRemarks, true)
                || $this->rowNames($row, $employee->id));

        return [
            'date' => $date,
            'rows' => $ownRows->concat($teamRows)->values(),
            'designation' => $ownRows->firstWhere('change_type', ReportingLineService::CHANGE_DESIGNATION),
            'category' => $ownRows->firstWhere('change_type', ReportingLineService::CHANGE_TARGET_CATEGORY),
        ];
    }

    /**
     * "Promotion: Caller → Team Leader. Target category: Silver → Gold." for
     * $employee's last recorded change (lastChange()), or null.
     */
    public function lastChangeSummary(Employee $employee): ?string
    {
        $change = $this->lastChange($employee);

        if ($change === null) {
            return null;
        }

        $designation = $change['designation'];
        $category = $change['category'];

        return trim($this->describe(
            (int) ($designation?->old_designation ?? $employee->designation),
            (int) ($designation?->new_designation ?? $employee->designation),
            $category ? $category->old_category : null,
            $category ? $category->new_category : null,
        ));
    }

    /**
     * Move $employee's last recorded position or target change (lastChange())
     * to $effectiveDate — for a promotion, demotion or new target keyed in
     * with the wrong date. Every row written with it moves together (their
     * own, and their team's re-slot, hand-over and re-fill rows), so every
     * month figure follows the corrected day. Rows after the new date that
     * only re-filed them under the same people are removed, as for a
     * back-dated change().
     *
     * @throws ValidationException when nothing was recorded, the date does
     *                             not move, is in the future, before they
     *                             joined or after they left, or would jump
     *                             past another recorded change
     */
    public function redateLastChange(Employee $employee, CarbonInterface $effectiveDate, ?User $performedBy = null): void
    {
        $newDay = CarbonImmutable::parse($effectiveDate->toDateString());

        DB::transaction(function () use ($employee, $newDay, $performedBy): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $change = $this->lastChange($employee) ?? $this->fail('effective_date', "No promotion, demotion or target change is recorded for {$employee->emp_name}.");
            $oldDay = $change['date'];

            $this->assertRedateAllowed($employee, $change, $newDay);

            $rows = $change['rows'];
            $note = ' Effective date corrected from '.$oldDay->format('d M Y').' to '.$newDay->format('d M Y')
                .($performedBy ? " by {$performedBy->name}" : '').'.';

            foreach ($rows as $row) {
                $isAnchor = in_array($row->change_type, [ReportingLineService::CHANGE_DESIGNATION, ReportingLineService::CHANGE_TARGET_CATEGORY], true);

                $row->forceFill([
                    'effective_date' => $newDay->toDateString(),
                    ...($isAnchor ? ['remarks' => mb_substr(trim(($row->remarks ?? '').$note), 0, 1000)] : []),
                ])->save();
            }

            $employeeIds = $rows->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->values();

            if ($newDay->lt($oldDay)) {
                foreach ($this->lineMovedIds($change) as $employeeId) {
                    $this->reportingLines->dropSupersededLines($employeeId, $newDay, $rows->modelKeys());
                }
            }

            foreach ($employeeIds as $employeeId) {
                $this->reportingLines->resequenceHistory($employeeId);
            }
        });

        TargetCategoryTimeline::forget();
        app(MonthlyTargetGate::class)->forget();
    }

    /**
     * "Team Leader since 01 Nov 2026 — promoted from Caller." for the
     * position $employee holds, or null when no dated change gave it them.
     */
    public function positionSince(Employee $employee): ?string
    {
        $row = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($row === null || (int) $row->new_designation !== (int) $employee->designation) {
            return null;
        }

        $labels = Employee::designationOptions();
        $verb = match ($this->kindOfChange((int) $row->old_designation, (int) $row->new_designation)) {
            'Promotion' => 'promoted',
            'Demotion' => 'demoted',
            default => 'moved',
        };

        return ($labels[$employee->designation] ?? '—').' since '.$row->effective_date->format('d M Y')
            ." — {$verb} from ".($labels[$row->old_designation] ?? '—').'.';
    }

    /**
     * "Alpha since 01 Nov 2026 (was Silver)." for the target category
     * $employee holds, or null when no dated change gave it them.
     */
    public function categorySince(Employee $employee): ?string
    {
        $row = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', ReportingLineService::CHANGE_TARGET_CATEGORY)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($row === null || (string) $row->new_category !== (string) $employee->category) {
            return null;
        }

        return (TargetCategory::labelFor($employee->category) ?? 'No category').' since '.$row->effective_date->format('d M Y')
            .' (was '.(TargetCategory::labelFor($row->old_category) ?? 'none').').';
    }

    /**
     * How many people report straight to $employee — given a new boss when
     * their position changes.
     */
    public function teamSize(Employee $employee): int
    {
        return $this->dependencies->directReports($employee)->count();
    }

    /**
     * Whether everybody reporting to $employee may stay with them at
     * $designation — it is still senior to every one of them.
     */
    public function teamCanStay(Employee $employee, ?int $designation): bool
    {
        return Employee::designationRank($designation) > $this->teamSeniorRank($employee);
    }

    /**
     * The rank of the most senior person reporting to $employee (0 with
     * nobody), so a form can tell cheaply whether the team may stay.
     */
    public function teamSeniorRank(Employee $employee): int
    {
        return (int) $this->dependencies->directReports($employee)
            ->pluck('designation')
            ->map(fn ($reportDesignation): int => Employee::designationRank((int) $reportDesignation))
            ->max();
    }

    /**
     * Who $employee's team may report to once their position changes:
     * anybody on the rolls more senior than all of them, but $employee.
     *
     * @return array<string, array<int, string>>
     */
    public function teamBossOptions(Employee $employee): array
    {
        return $this->dependencies->newBossOptions($employee);
    }

    /**
     * The earliest day a change may take effect: not before they joined,
     * nor before the last recorded change it would come after (a reporting
     * or designation change for a new designation, a category change for a
     * new category) — an earlier date would rewrite that later change.
     */
    public function earliestEffectiveDate(Employee $employee, bool $designationChanges, bool $categoryChanges): ?CarbonImmutable
    {
        $dates = [];

        if (filled($employee->doj)) {
            $dates[] = CarbonImmutable::parse($employee->doj)->startOfDay();
        }

        if ($designationChanges) {
            // A row that only re-filed them under the same people (after a
            // boss's designation change) holds nothing back: the change
            // replaces it (dropSupersededLines()).
            $lastLineChange = EmployeeReportingHistory::query()
                ->toBase()
                ->where('employee_id', $employee->id)
                ->whereNotIn('change_type', ['exit', ReportingLineService::CHANGE_TARGET_CATEGORY])
                ->get()
                ->filter(fn (object $row): bool => $row->change_type === ReportingLineService::CHANGE_DESIGNATION
                    || ReportingLineService::movesEmployee($row))
                ->map(fn (object $row): string => substr((string) $row->effective_date, 0, 10))
                ->max();

            if ($lastLineChange !== null) {
                $dates[] = CarbonImmutable::parse($lastLineChange)->startOfDay();
            }
        }

        if ($categoryChanges && ($lastCategoryChange = TargetCategoryTimeline::lastChangeDate($employee)) !== null) {
            $dates[] = $lastCategoryChange;
        }

        return $dates === [] ? null : max($dates);
    }

    /**
     * "Promotion", "Demotion" or "Position change" from $from to $to.
     */
    public function kindOfChange(int $from, int $to): string
    {
        return match (Employee::designationRank($to) <=> Employee::designationRank($from)) {
            1 => 'Promotion',
            -1 => 'Demotion',
            default => 'Position change',
        };
    }

    /**
     * "Promotion: Team Leader → Manager. Target category: Silver → Gold."
     */
    public function describe(int $previousDesignation, int $designation, ?string $previousCategory, ?string $category): string
    {
        $positions = Employee::designationOptions();
        $parts = [];

        if ($designation !== $previousDesignation) {
            $parts[] = $this->kindOfChange($previousDesignation, $designation).': '
                .($positions[$previousDesignation] ?? '—').' → '.($positions[$designation] ?? '—').'.';
        }

        if ($category !== $previousCategory) {
            $parts[] = 'Target category: '.(TargetCategory::labelFor($previousCategory) ?? 'none')
                .' → '.(TargetCategory::labelFor($category) ?? 'none').'.';
        }

        return implode(' ', $parts);
    }

    /**
     * Whether an employee at $designation reports to anybody at all.
     */
    public function reportsToBoss(?int $designation): bool
    {
        return Employee::designationRank($designation) > 0
            && $designation !== Employee::DESIGNATION_BUSINESS_HEAD;
    }

    private function assertChangeAllowed(
        Employee $employee,
        int $designation,
        ?string $category,
        ?string $position,
        bool $designationChanges,
        bool $categoryChanges,
    ): void {
        if (strtolower(trim((string) $employee->exit_status)) === 'yes') {
            $this->fail('designation', "{$employee->emp_name} is no longer on the rolls.");
        }

        if (! $designationChanges && ! $categoryChanges) {
            $this->fail('designation', 'Choose a new position or a new target category — nothing would change.');
        }

        if (! array_key_exists($designation, Position::optionsIncluding($employee->designation))) {
            $this->fail('designation', 'Choose an active position from the list.');
        }

        if ($category === null && Employee::designationRank($designation) > 0) {
            $this->fail('category', 'Choose a target category: this position carries a target.');
        }

        if ($category !== null && ! array_key_exists($category, TargetCategory::optionsIncluding($employee->category))) {
            $this->fail('category', 'Choose an active target category from the list.');
        }

        if ($position !== null && ! array_key_exists($position, Designation::optionsIncluding($employee->position))) {
            $this->fail('position', 'Choose an active designation from the list.');
        }
    }

    private function assertEffectiveDateAllowed(Employee $employee, CarbonImmutable $effectiveDay, bool $designationChanges, bool $categoryChanges): void
    {
        if ($effectiveDay->isAfter(today())) {
            $this->fail('effective_date', 'The effective date cannot be in the future.');
        }

        $earliest = $this->earliestEffectiveDate($employee, $designationChanges, $categoryChanges);

        if ($earliest !== null && $effectiveDay->lt($earliest)) {
            $this->fail('effective_date', 'The effective date cannot be before '.$earliest->format('d M Y').' — their joining date or last recorded change.');
        }
    }

    /**
     * @param  array{date: CarbonImmutable, rows: Collection<int, EmployeeReportingHistory>, designation: ?EmployeeReportingHistory, category: ?EmployeeReportingHistory}  $change
     */
    private function assertRedateAllowed(Employee $employee, array $change, CarbonImmutable $newDay): void
    {
        $oldDay = $change['date'];

        if ($newDay->equalTo($oldDay)) {
            $this->fail('effective_date', 'The change already takes effect on '.$oldDay->format('d M Y').'.');
        }

        if ($newDay->isAfter(today())) {
            $this->fail('effective_date', 'The effective date cannot be in the future.');
        }

        if (filled($employee->doj) && $newDay->lt(CarbonImmutable::parse($employee->doj)->startOfDay())) {
            $this->fail('effective_date', 'The effective date cannot be before they joined on '.CarbonImmutable::parse($employee->doj)->format('d M Y').'.');
        }

        if (strtolower(trim((string) $employee->exit_status)) === 'yes' && filled($employee->exit_date)
            && $newDay->gt(CarbonImmutable::parse($employee->exit_date)->startOfDay())) {
            $this->fail('effective_date', 'The effective date cannot be after they left on '.CarbonImmutable::parse($employee->exit_date)->format('d M Y').'.');
        }

        // Moving back: rows strictly between the two days. Moving forward:
        // rows after the old day up to and including the new one.
        $earlier = $newDay->lt($oldDay);
        $lineMovedIds = $this->lineMovedIds($change);
        $names = Employee::query()->whereIn('id', $change['rows']->pluck('employee_id'))->pluck('emp_name', 'id');

        $inBetween = EmployeeReportingHistory::query()
            ->whereIn('employee_id', $change['rows']->pluck('employee_id')->unique())
            ->whereKeyNot($change['rows']->modelKeys())
            ->where('change_type', '!=', 'exit')
            ->whereDate('effective_date', '>', ($earlier ? $newDay : $oldDay)->toDateString())
            ->whereDate('effective_date', $earlier ? '<' : '<=', ($earlier ? $oldDay : $newDay)->toDateString())
            ->orderBy('effective_date')
            ->get();

        foreach ($inBetween as $row) {
            $isOwn = (int) $row->employee_id === $employee->id;

            $blocks = match ($row->change_type) {
                ReportingLineService::CHANGE_DESIGNATION => $isOwn && $change['designation'] !== null,
                ReportingLineService::CHANGE_TARGET_CATEGORY => $isOwn && $change['category'] !== null,
                default => in_array((int) $row->employee_id, $lineMovedIds, true) && ReportingLineService::movesEmployee($row),
            };

            if ($blocks) {
                $this->fail('effective_date', 'The effective date cannot move past '
                    .($isOwn ? 'their' : ($names[$row->employee_id] ?? 'a team member')."'s")
                    .' other change recorded on '.$row->effective_date->format('d M Y')
                    .'. Choose a date on the same side of it.');
            }
        }
    }

    /**
     * Who had their reporting line moved by $change: the employee when it
     * changed their position or their own line, and every team member it
     * re-slotted, handed over or re-filled.
     *
     * @param  array{rows: Collection<int, EmployeeReportingHistory>, category: ?EmployeeReportingHistory}  $change
     * @return list<int>
     */
    private function lineMovedIds(array $change): array
    {
        return $change['rows']
            ->reject(fn (EmployeeReportingHistory $row): bool => $row->change_type === ReportingLineService::CHANGE_TARGET_CATEGORY)
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether $row's old or new reporting columns name $employeeId.
     */
    private function rowNames(EmployeeReportingHistory $row, int $employeeId): bool
    {
        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            if ((int) $row->{'old_'.$column} === $employeeId || (int) $row->{'new_'.$column} === $employeeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Give everybody reporting to $employee the new boss $teamBossId from
     * $effectiveDay, before $employee's own position changes — or, with no
     * $teamBossId, keep them with $employee when $designation is still
     * senior to them all. Their work stays with them either way.
     *
     * @return list<int> who stays and must be put back under $employee once
     *                   the new position is saved (exited reports the new
     *                   position is still senior to included)
     */
    private function moveTeam(Employee $employee, int $designation, ?int $teamBossId, CarbonImmutable $effectiveDay, ?User $performedBy): array
    {
        $teamSize = $this->teamSize($employee);

        if ($teamSize === 0) {
            return [];
        }

        if ($teamBossId === null || $teamBossId === $employee->id) {
            if ($this->teamCanStay($employee, $designation)) {
                $tree = ReportingTree::load();
                $newRank = Employee::designationRank($designation);

                return array_values(array_filter(
                    $tree->childIds($employee->id),
                    fn (int $reportId): bool => Employee::designationRank($tree->designation($reportId)) < $newRank,
                ));
            }

            $label = Employee::designationOptions()[$designation] ?? 'their new position';

            $this->fail('team_boss', "{$employee->emp_name} still has {$this->dependencies->describe([EmployeeDependencyService::DIRECT_REPORTS => $teamSize])} who would no longer have a more senior boss as a {$label}. Choose who they report to from the effective date.");
        }

        $this->dependencies->moveDirectReports($employee, $teamBossId, $effectiveDay, $performedBy);

        return [];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
