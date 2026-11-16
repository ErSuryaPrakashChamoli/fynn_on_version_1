<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Position;
use App\Models\TargetCategory;
use App\Models\User;
use App\Support\TargetCategoryTimeline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotes, demotes or changes the target category of an employee on the
 * rolls — from the "Change Position / Target" action — only once nothing is
 * attached to them (EmployeeDependencyService): their team, open customers,
 * leads and delegations are handed over first (a plain transfer hands over
 * less — EmployeeDependencyService::SCOPE_MOVE).
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
     * when given.
     *
     * @throws ValidationException while anything is still attached, nothing
     *                             would change, a value is not offered, the
     *                             boss is not allowed, or the date is out of range
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
    ): void {
        $category = filled($category) ? $category : null;
        $position = filled($position) ? $position : null;
        $effectiveDay = CarbonImmutable::parse($effectiveDate->toDateString());

        DB::transaction(function () use ($employee, $designation, $bossId, $category, $position, $effectiveDay, $remarks, $performedBy): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $previousDesignation = (int) $employee->designation;
            $previousCategory = filled($employee->category) ? (string) $employee->category : null;
            $designationChanges = $designation !== $previousDesignation;
            $categoryChanges = $category !== $previousCategory;

            $this->assertChangeAllowed($employee, $designation, $category, $position, $designationChanges, $categoryChanges);
            $this->assertEffectiveDateAllowed($employee, $effectiveDay, $designationChanges, $categoryChanges);

            $this->dependencies->assertNothingAttached($employee, 'dependencies');

            $bossId = $this->reportsToBoss($designation) ? $bossId : null;

            if ($designationChanges && ($problem = $this->reportingLines->bossProblem($designation, $bossId, $employee->id)) !== null) {
                $this->fail('reports_to', $problem);
            }

            $remarks = filled($remarks) ? trim($remarks) : $this->describe($previousDesignation, $designation, $previousCategory, $category);

            $employee->forceFill([
                'designation' => $designation,
                'category' => $category,
                ...($position !== null ? ['position' => $position] : []),
            ])->save();

            if ($designationChanges) {
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
        });

        app(MonthlyTargetGate::class)->forget();
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
            $lastLineChange = EmployeeReportingHistory::query()
                ->where('employee_id', $employee->id)
                ->whereNotIn('change_type', ['exit', ReportingLineService::CHANGE_TARGET_CATEGORY])
                ->max('effective_date');

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

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
