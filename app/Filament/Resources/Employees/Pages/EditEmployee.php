<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use App\Support\ReportingTree;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

        if (($data['exit_status'] ?? 'no') === 'no') {
            $data['exit_date'] = null;
        }
        $this->oldReporting = [
            'superviser_id' => $this->record->superviser_id,
            'manager_id' => $this->record->manager_id,
            'cluster_id' => $this->record->cluster_id,
            'business_head_id' => $this->record->business_head_id,
            'designation' => $this->record->designation,
            'reporting_date' => $this->record->reporting_date,
            'exit_status' => $this->record->exit_status,
            'exit_date' => $this->record->exit_date,
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

        /*
        |--------------------------------------------------------------------------
        | EXIT
        |--------------------------------------------------------------------------
        */

        if (
            $this->oldReporting['exit_status'] != $employee->exit_status &&
            $employee->exit_status === 'yes'
        ) {
            $exitDate = $employee->exit_date ?? now()->toDateString();

            EmployeeReportingHistory::where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => $exitDate,
                    'updated_at' => now(),
                ]);

            EmployeeReportingHistory::create([
                'employee_id' => $employee->id,
                'old_superviser_id' => $employee->superviser_id,
                'old_manager_id' => $employee->manager_id,
                'old_cluster_id' => $employee->cluster_id,
                'old_business_head_id' => $employee->business_head_id,
                'new_superviser_id' => null,
                'new_manager_id' => null,
                'new_cluster_id' => null,
                'new_business_head_id' => null,
                'effective_date' => $exitDate,
                'change_type' => 'exit',
                'updated_by' => auth()->id(),
                'remarks' => 'Employee exited.',
            ]);
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
            DeleteAction::make(),

            Action::make('transferEmployee')
                ->label('Transfer Employee')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => Employee::designationRank($this->record->designation) > 0
                    && $this->record->designation !== Employee::DESIGNATION_BUSINESS_HEAD)
                ->fillForm(fn (): array => [
                    'current_reporting' => ($bossId = HierarchyHelper::directBossId($this->record))
                        ? app(ReportingLineService::class)->lineSummary($bossId)
                        : 'Nobody',
                    'effective_date' => now()->toDateString(),
                ])
                ->form([

                    TextInput::make('current_reporting')
                        ->label('Currently Reports To')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('reports_to')
                        ->label('New Boss')
                        ->options(fn (): array => app(ReportingLineService::class)
                            ->bossOptions($this->record->designation, $this->record->id))
                        ->helperText('Anyone more senior may be chosen; the levels in between can be skipped.')
                        ->required()
                        ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                            $problem = app(ReportingLineService::class)
                                ->bossProblem($this->record->designation, (int) $value, $this->record->id);

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        })
                        ->native(false),

                    DatePicker::make('effective_date')
                        ->required(),

                    Textarea::make('remarks')
                        ->rows(3),

                ])
                ->action(function (array $data) {

                    // reporting_date is deliberately left untouched here: this transfer
                    // moves an existing, active employee — it must not make
                    // AchievementCalculatorService's "new joiner" worked-days rule treat
                    // them as newly hired for the transfer month. $data['effective_date']
                    // is still recorded on the EmployeeReportingHistory entries.
                    app(ReportingLineService::class)->reportTo(
                        $this->record,
                        (int) $data['reports_to'],
                        $data['effective_date'],
                        ReportingLineService::CHANGE_TRANSFER,
                        filled($data['remarks'] ?? null) ? $data['remarks'] : 'Transferred from Employee Edit.',
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Employee transferred successfully.')
                        ->success()
                        ->send();

                    $this->data['reports_to'] = HierarchyHelper::directBossId($this->record);

                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
