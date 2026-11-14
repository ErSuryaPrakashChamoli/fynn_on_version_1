<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Actions\InactivateEmployeeAction;
use App\Filament\Actions\TransferEmployeeAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use App\Support\ReportingTree;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * The employee's own reporting line, a designation change and the
     * re-filling of the team below all land together or not at all.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected array $oldReporting = [];

    /** The boss chosen in the form's Reports To field. */
    protected ?int $newBossId = null;

    /**
     * Who reported straight to this employee before the save. After a
     * promotion or demotion they belong in a different reporting column.
     *
     * @var array<int, int>
     */
    protected array $directReportIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['reports_to'] = HierarchyHelper::directBossId($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // An employee on the rolls leaves only through "Mark Inactive",
        // which first clears whatever still depends on them; the form
        // can only bring an exited employee back or correct their date.
        if (! EmployeeResource::hasLeft($this->record)) {
            unset($data['exit_status'], $data['exit_date']);
        } elseif (($data['exit_status'] ?? 'no') === 'no') {
            $data['exit_date'] = null;
        }

        $this->oldReporting = [
            'designation' => $this->record->designation,
            'reporting_date' => $this->record->reporting_date,
        ];

        $this->newBossId = filled($data['reports_to'] ?? null) ? (int) $data['reports_to'] : null;
        $this->directReportIds = ReportingTree::load()->childIds($this->record->id);

        // Not a column: the reporting columns are derived from it in afterSave().
        unset($data['reports_to']);

        return $data;
    }

    protected function afterSave(): void
    {
        $employee = $this->record;
        $reportingLines = app(ReportingLineService::class);

        /*
        |--------------------------------------------------------------------------
        | REPORTING LINE
        |--------------------------------------------------------------------------
        |
        | Always re-applied: it writes nothing when nothing moved, and it
        | also tidies columns that had drifted from the chosen boss (e.g. a
        | Manager filed in superviser_id). Everyone below follows.
        |
        */

        $effectiveDate = $this->reportingEffectiveDate();

        $reportingLines->reportTo(
            $employee,
            $this->newBossId,
            $effectiveDate,
            ReportingLineService::CHANGE_REPORTING,
            'Reporting hierarchy updated from Employee Edit.',
            auth()->id(),
        );

        if ((int) ($this->oldReporting['designation'] ?? $employee->designation) !== (int) $employee->designation) {
            foreach (Employee::query()->whereIn('id', $this->directReportIds)->get() as $report) {
                $reportingLines->reportTo(
                    $report,
                    $employee->id,
                    $effectiveDate,
                    ReportingLineService::CHANGE_REPORTING,
                    "Follows {$employee->emp_name}'s designation change.",
                    auth()->id(),
                );
            }
        }
    }

    /**
     * The date a reporting change made on this form takes effect.
     *
     * A reporting-hierarchy change moves an existing, active employee — it
     * must never reset reporting_date merely because the admin didn't type
     * a new one here, since that would make AchievementCalculatorService's
     * "new joiner" worked-days rule wrongly treat them as newly hired for
     * the current month. A reporting date the admin did change dates the
     * history entries; otherwise they take effect today.
     */
    private function reportingEffectiveDate(): string
    {
        $reportingDate = $this->record->reporting_date;

        if (
            blank($reportingDate) ||
            (string) $reportingDate === (string) ($this->oldReporting['reporting_date'] ?? null)
        ) {
            return now()->toDateString();
        }

        return Carbon::parse($reportingDate)->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            InactivateEmployeeAction::make(),
            TransferEmployeeAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
