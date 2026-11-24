<?php

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Services\EmployeePositionChangeService;
use App\Services\ReportingLineService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Punit Kumar (ME0201) was promoted Caller → Team Leader from 1 Nov 2026
 * (user, 2026-11-24), but the promotion was recorded from a later day: the
 * date picker would not go back past a "Follows Manoj Sajwan's designation
 * change." row of 14 Nov that had not moved him at all. November therefore
 * still counted him as a caller in his old Team Leader's team for most of
 * the month, and his own team target followed only from the later day.
 *
 * Moves the recorded promotion — with the team rows written alongside it —
 * to 1 Nov through the same service as the "Correct Effective Date" button,
 * or dates it there when no promotion row was ever written. Anything the
 * service refuses is logged and left for that button.
 */
return new class extends Migration
{
    private const EMP_ID = 'ME0201';

    private const EFFECTIVE_DATE = '2026-11-01';

    public function up(): void
    {
        $employee = Employee::query()->firstWhere('emp_id', self::EMP_ID);

        if (! $employee || (int) $employee->designation !== Employee::DESIGNATION_TEAM_LEADER) {
            return;
        }

        $promotion = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        try {
            if ($promotion === null) {
                $this->datePromotion($employee);

                return;
            }

            if ((int) $promotion->new_designation !== Employee::DESIGNATION_TEAM_LEADER
                || $promotion->effective_date->toDateString() === self::EFFECTIVE_DATE) {
                return;
            }

            $service = app(EmployeePositionChangeService::class);

            if ($service->lastChange($employee)['date']->toDateString() !== $promotion->effective_date->toDateString()) {
                Log::warning(self::EMP_ID.': a later change was recorded after the promotion; correct its date with "Correct Effective Date".');

                return;
            }

            $service->redateLastChange($employee, Carbon::parse(self::EFFECTIVE_DATE));
        } catch (ValidationException $exception) {
            Log::warning(self::EMP_ID.': promotion date not corrected — '.collect($exception->errors())->flatten()->first());
        }
    }

    public function down(): void
    {
        // A data correction: the previous date was wrong and is not restored.
    }

    /**
     * Promoted without a dated row: every month read him as a Team Leader,
     * under today's boss.
     */
    private function datePromotion(Employee $employee): void
    {
        $reportingLines = app(ReportingLineService::class);

        $reportingLines->dropSupersededLines($employee->id, self::EFFECTIVE_DATE);

        $lastLine = EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('change_type', ReportingLineService::NON_REPORTING_CHANGES)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        $columns = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $columns['old_'.$column] = $lastLine?->{'new_'.$column};
            $columns['new_'.$column] = $employee->{$column};
        }

        // Up to 31 Oct he reported through his last recorded line.
        if ($lastLine !== null && ReportingLineService::movesEmployee((object) ['change_type' => 'reporting_change', ...$columns])) {
            EmployeeReportingHistory::query()->create([
                'employee_id' => $employee->id,
                ...$columns,
                'effective_date' => self::EFFECTIVE_DATE,
                'change_type' => ReportingLineService::CHANGE_REPORTING,
                'remarks' => 'Promotion: Caller → Team Leader. Dated from 01 Nov 2026 (data correction).',
            ]);
        }

        $reportingLines->recordDesignationChange(
            $employee,
            Employee::DESIGNATION_CALLER,
            self::EFFECTIVE_DATE,
            'Promotion: Caller → Team Leader. Dated from 01 Nov 2026 (data correction).',
        );
        $reportingLines->resequenceHistory($employee->id);
    }
};
