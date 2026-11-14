<?php

namespace App\Filament\Imports;

use App\Models\Employee;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeInactivationService;
use App\Services\EmployeeTransferService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use App\Support\ReportingTree;
use DateTimeImmutable;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use League\Csv\Bom;
use League\Csv\Writer;

/**
 * Runs in one of two modes, picked by the `mode` import option:
 * MODE_CREATE adds employees who are not on the list yet, MODE_UPDATE
 * changes employees who are, matched by emp_id. Without the option a
 * file is imported as new employees.
 *
 * The status / inactive_date columns go through the same paths as the
 * Edit page: leaving only via EmployeeInactivationService::inactivate(),
 * coming back or a corrected exit date as plain column changes.
 */
class EmployeeImporter extends Importer
{
    public const MODE_CREATE = 'create';

    public const MODE_UPDATE = 'update';

    /**
     * The `move` option — how somebody given a new boss (same position)
     * moves: alone, refused while anything is still attached to them, as
     * "Transfer Employee" does; or with everyone under them, keeping their
     * customers and leads, as a Full Cluster Transfer does.
     */
    public const MOVE_ALONE = 'alone';

    public const MOVE_WITH_TEAM = 'with_team';

    /**
     * Spreadsheet apps re-save dates in the local format, so these are
     * all read as dates and stored as Y-m-d.
     */
    private const DATE_FORMATS = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd M Y'];

    protected static ?string $model = Employee::class;

    /** The employee this row reports to, resolved from its employee IDs. */
    protected ?int $bossId = null;

    protected bool $recordExisted = false;

    /** Whether an updated employee moves to a new boss or changes designation. */
    protected bool $reportingLineChanged = false;

    /** Whether an updated employee keeps their position and moves to a new boss. */
    protected bool $straightMove = false;

    /**
     * Who reported straight to an employee whose designation changes.
     *
     * @var list<int>
     */
    protected array $directReportIds = [];

    /** The inactive_date of an employee this row takes off the rolls. */
    protected ?string $inactivateOn = null;

    private const STATUS_ACTIVE = 'active';

    private const STATUS_INACTIVE = 'inactive';

    public static function getColumns(): array
    {
        return [

            ImportColumn::make('emp_id')
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('emp_name')
                ->requiredMapping()
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(fn (array $options): array => [
                    static::updatesExisting($options) ? 'nullable' : 'required',
                    'max:255',
                ]),
            ImportColumn::make('email')
                ->requiredMapping()
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(fn (array $options, ?Employee $record): array => [
                    static::updatesExisting($options) ? 'nullable' : 'required',
                    'max:255',
                    Rule::unique('employees', 'email')->ignore($record?->id),
                ]),
            // The designation's name (e.g. "Caller") or its code.
            ImportColumn::make('designation')
                ->requiredMapping()
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->castStateUsing(fn (mixed $state): mixed => static::designationCodeFor($state))
                ->rules(fn (array $options): array => [
                    static::updatesExisting($options) ? 'nullable' : 'required',
                    Rule::in(array_keys(Employee::designationOptions())),
                ]),
            ImportColumn::make('doj')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->castStateUsing(fn (mixed $state): mixed => static::normalizedDate($state))
                ->rules(['nullable', 'date_format:Y-m-d']),
            ImportColumn::make('reporting_date')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->castStateUsing(fn (mixed $state): mixed => static::normalizedDate($state))
                ->rules(['nullable', 'date_format:Y-m-d']),
            // The Employee ID of whoever this employee reports to, at any
            // more senior level. Not a column: every reporting column is
            // derived from it — see ReportingLineService.
            ImportColumn::make('reports_to')
                ->label('Reports To (Employee ID)')
                ->rules(['nullable', 'string', 'max:255'])
                ->fillRecordUsing(fn (): null => null),
            // Older import files name the Team Leader / Manager instead;
            // the nearest one given is used as the boss.
            ImportColumn::make('superviser_id')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('manager_id')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('cost_center')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(['max:255']),
            ImportColumn::make('unit_name')
                ->ignoreBlankState(fn (array $options): bool => static::updatesExisting($options))
                ->rules(['max:255']),
            // Active or Inactive. Not columns: taking somebody off the
            // rolls goes through EmployeeInactivationService — see
            // applyStatus().
            ImportColumn::make('status')
                ->label('Status (Active / Inactive)')
                ->castStateUsing(fn (mixed $state): ?string => blank($state) ? null : Str::lower(trim((string) $state)))
                ->rules(['nullable', Rule::in([self::STATUS_ACTIVE, self::STATUS_INACTIVE])])
                ->fillRecordUsing(fn (): null => null),
            // The first day an Inactive employee is off the rolls (exit_date).
            ImportColumn::make('inactive_date')
                ->guess(['exit_date'])
                ->castStateUsing(fn (mixed $state): mixed => static::normalizedDate($state))
                ->rules(['nullable', 'date_format:Y-m-d'])
                ->fillRecordUsing(fn (): null => null),
        ];
    }

    /**
     * Each row saves in full or not at all, so a later step the services
     * refuse (a reporting move, an inactivation) leaves none of the row's
     * other changes behind.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): void
    {
        DB::transaction(fn () => parent::__invoke($data));
    }

    /**
     * Every employee, active and inactive, as a CSV in the shape "Update
     * Existing Employees" reads back, so the downloaded file can be
     * edited and uploaded as it is.
     *
     * Lowest level first: rows are imported top to bottom, so callers
     * handed to a new Team Leader have moved by the time their old Team
     * Leader's row moves them alone.
     */
    public static function existingEmployeesCsv(): string
    {
        $tree = ReportingTree::load();
        $designations = Employee::designationOptions();
        $empIds = Employee::query()->pluck('emp_id', 'id');

        $csv = Writer::fromString();
        $csv->setOutputBOM(Bom::Utf8);
        $csv->insertOne(['emp_id', 'emp_name', 'email', 'designation', 'doj', 'reporting_date', 'reports_to', 'cost_center', 'unit_name', 'status', 'inactive_date']);

        Employee::query()
            ->get()
            ->sortBy([
                fn (Employee $a, Employee $b): int => Employee::designationRank($a->designation) <=> Employee::designationRank($b->designation),
                fn (Employee $a, Employee $b): int => strcmp($a->emp_id, $b->emp_id),
            ])
            ->each(function (Employee $employee) use ($csv, $tree, $designations, $empIds): void {
                $bossId = $tree->bossId($employee->id);
                $hasLeft = static::hasLeft($employee);

                $csv->insertOne([
                    $employee->emp_id,
                    $employee->emp_name,
                    $employee->email,
                    $designations[$employee->designation] ?? $employee->designation,
                    $employee->doj,
                    $employee->reporting_date,
                    $bossId !== null ? $empIds->get($bossId) : null,
                    $employee->cost_center,
                    $employee->unit_name,
                    $hasLeft ? 'Inactive' : 'Active',
                    $hasLeft ? $employee->exit_date : null,
                ]);
            });

        return $csv->toString();
    }

    public function resolveRecord(): Employee
    {
        foreach (['superviser_id', 'manager_id'] as $column) {
            if (! empty($this->data[$column])) {
                $this->data[$column] = $this->employeeIdFor($this->data[$column]);
            }
        }

        $this->bossId = $this->employeeIdFor($this->data['reports_to'] ?? null)
            ?? (filled($this->data['superviser_id'] ?? null) ? (int) $this->data['superviser_id'] : null)
            ?? (filled($this->data['manager_id'] ?? null) ? (int) $this->data['manager_id'] : null);

        $empId = $this->data['emp_id'] ?? null;
        $employee = filled($empId) ? Employee::query()->firstWhere('emp_id', $empId) : null;

        if (static::updatesExisting($this->options)) {
            return $employee ?? throw new RowImportFailedException(filled($empId)
                ? "No employee with ID {$empId}. Use \"Upload New Employees\" to add them."
                : 'emp_id is required to find the employee to update.');
        }

        if ($employee) {
            throw new RowImportFailedException("Employee {$empId} already exists. Use \"Update Existing Employees\" to change their details.");
        }

        return new Employee(['emp_id' => $empId]);
    }

    /**
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        return [
            'designation.in' => 'Unknown designation. Use one of: '.implode(', ', Employee::designationOptions()).'.',
            'doj.date_format' => 'doj must be a date such as 2025-01-15 or 15-01-2025.',
            'reporting_date.date_format' => 'reporting_date must be a date such as 2025-01-15 or 15-01-2025.',
            'status.in' => 'status must be Active or Inactive.',
            'inactive_date.date_format' => 'inactive_date must be a date such as 2025-01-15 or 15-01-2025.',
        ];
    }

    protected function beforeSave(): void
    {
        if (filled($this->data['reports_to'] ?? null) && $this->employeeIdFor($this->data['reports_to']) === null) {
            throw new RowImportFailedException("No employee with ID {$this->data['reports_to']} to report to.");
        }

        $this->recordExisted = $this->record->exists;
        $this->reportingLineChanged = false;
        $this->straightMove = false;
        $this->directReportIds = [];
        $this->inactivateOn = null;
        $reportingLines = app(ReportingLineService::class);
        $hadLeft = $this->recordExisted && static::hasLeft($this->record);

        $comesBack = $this->applyStatus();

        if ($this->recordExisted) {
            // An update that names no boss keeps the one the employee has.
            $currentBossId = HierarchyHelper::directBossId($this->record);
            $this->bossId ??= $currentBossId;

            // Moved in afterSave() instead, so the change is recorded and
            // the team below follows.
            foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
                $this->record->{$column} = $this->record->getOriginal($column);
            }

            // Somebody coming back needs a boss who is still on the rolls.
            $this->reportingLineChanged = $this->bossId !== $currentBossId
                || $this->record->isDirty('designation')
                || $comesBack;

            // A row that leaves the line alone is not re-checked, so an
            // untouched row under somebody who has since left still saves.
            if (! $this->reportingLineChanged) {
                return;
            }

            if ($this->record->isDirty('designation')
                && ($problem = $reportingLines->designationProblem($this->record, $this->record->designation)) !== null) {
                throw new RowImportFailedException("{$this->record->emp_id}: {$problem}");
            }

            // Read before the save: once the designation changes, the
            // tree no longer finds them in the column they sit in.
            $this->directReportIds = $this->record->isDirty('designation')
                ? ReportingTree::load()->childIds($this->record->id)
                : [];

            // Same position, new boss: a transfer, as on the Edit page
            // (EmployeeResource::moveProblem). A promotion or demotion
            // re-slots their team instead.
            $this->straightMove = ! $hadLeft
                && ! $this->record->isDirty('designation')
                && $this->bossId !== $currentBossId;
        }

        $designation = filled($this->record->designation) ? (int) $this->record->designation : null;
        $problem = $reportingLines->bossProblem($designation, $this->bossId, $this->record->id);

        if ($problem !== null) {
            throw new RowImportFailedException("{$this->record->emp_id}: {$problem}");
        }

        if ($this->recordExisted) {
            $this->refuseMovingWithWorkAttached();

            return;
        }

        // Filled before the insert, so the joining history row carries
        // the full reporting line.
        $this->record->forceFill($reportingLines->columnsUnder($this->bossId));
    }

    protected function afterSave(): void
    {
        if ($this->straightMove && ! $this->movesWithTeam()) {
            app(EmployeeTransferService::class)->transfer(
                $this->record,
                $this->bossId,
                today(),
                'Moved by employee import.',
                $this->import->user,
            );
        } elseif ($this->recordExisted && $this->reportingLineChanged) {
            // reportTo() re-fills everyone below, so the team follows.
            app(ReportingLineService::class)->reportTo(
                $this->record,
                $this->bossId,
                today(),
                $this->straightMove ? ReportingLineService::CHANGE_TRANSFER : ReportingLineService::CHANGE_REPORTING,
                $this->straightMove ? 'Moved with their team by employee import.' : 'Reporting line updated by employee import.',
                $this->import->user_id,
            );

            // A promotion or demotion re-slots their team, as on the Edit page.
            foreach (Employee::query()->whereIn('id', $this->directReportIds)->get() as $report) {
                app(ReportingLineService::class)->reportTo(
                    $report,
                    $this->record->id,
                    today(),
                    ReportingLineService::CHANGE_REPORTING,
                    "Follows {$this->record->emp_name}'s designation change.",
                    $this->import->user_id,
                );
            }
        }

        if ($this->inactivateOn !== null) {
            app(EmployeeInactivationService::class)->inactivate(
                $this->record,
                Carbon::parse($this->inactivateOn),
                'Marked inactive by employee import.',
                $this->import->user,
            );
        }
    }

    /**
     * Moving alone waits until their team, open customers, leads and
     * delegations have been handed over.
     */
    private function refuseMovingWithWorkAttached(): void
    {
        if (! $this->straightMove || $this->movesWithTeam()) {
            return;
        }

        $dependencies = app(EmployeeDependencyService::class);
        $attached = $dependencies->dependencyCounts($this->record);

        if ($attached !== []) {
            throw new RowImportFailedException(
                "{$this->record->emp_id}: {$this->record->emp_name} still has {$dependencies->describe($attached)}. "
                .'Hand them over first, or upload again choosing "Move the employee with their team".',
            );
        }
    }

    private function movesWithTeam(): bool
    {
        return ($this->options['move'] ?? self::MOVE_ALONE) === self::MOVE_WITH_TEAM;
    }

    /**
     * Applies the row's status and inactive_date (blank keeps what the
     * employee has). Taking somebody off the rolls waits for afterSave(),
     * where EmployeeInactivationService refuses while anything still
     * depends on them. Bringing somebody back, or correcting the date of
     * somebody who has left, is a plain column change, as on the Edit page.
     *
     * @return bool whether an employee who had left is coming back
     */
    private function applyStatus(): bool
    {
        $hasLeft = $this->recordExisted && static::hasLeft($this->record);
        $status = $this->data['status'] ?? null;
        $inactiveDate = $this->data['inactive_date'] ?? null;
        $empId = $this->record->emp_id;

        $endsInactive = $status === null ? $hasLeft : $status === self::STATUS_INACTIVE;

        if (! $endsInactive) {
            if (filled($inactiveDate)) {
                throw new RowImportFailedException("{$empId}: inactive_date is only for Inactive employees. Clear it, or set status to Inactive.");
            }

            if (! $hasLeft) {
                return false;
            }

            $this->record->exit_status = 'no';
            $this->record->exit_date = null;

            return true;
        }

        if (! $hasLeft) {
            $this->inactivateOn = $inactiveDate
                ?? throw new RowImportFailedException("{$empId}: inactive_date is required to mark them Inactive.");

            return false;
        }

        if (filled($inactiveDate) && $inactiveDate !== substr((string) $this->record->exit_date, 0, 10)) {
            if (Carbon::parse($inactiveDate)->isAfter(today())) {
                throw new RowImportFailedException("{$empId}: inactive_date cannot be in the future.");
            }

            if (filled($this->record->doj) && Carbon::parse($inactiveDate)->lt(Carbon::parse($this->record->doj)->startOfDay())) {
                throw new RowImportFailedException("{$empId}: inactive_date cannot be before they joined on {$this->record->doj}.");
            }

            $this->record->exit_date = $inactiveDate;
        }

        return false;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $verb = static::updatesExisting($import->getOptions()) ? 'updated' : 'imported';

        $body = 'Your employee import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' '.$verb.'.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    protected function beforeValidation(): void
    {
        // This logs the raw CSV row data right before Filament validates it
        logger('Importing Row Data:', $this->data);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected static function updatesExisting(array $options): bool
    {
        return ($options['mode'] ?? self::MODE_CREATE) === self::MODE_UPDATE;
    }

    /**
     * The designation code for a name such as "Team Leader", or the
     * state unchanged when it is a code already or matches no name (the
     * `in` rule then rejects it).
     */
    protected static function designationCodeFor(mixed $state): mixed
    {
        if (blank($state) || is_numeric($state)) {
            return blank($state) ? null : (int) $state;
        }

        $code = array_search(
            Str::lower(trim((string) $state)),
            array_map(fn (string $label): string => Str::lower(trim($label)), Employee::designationOptions()),
            true,
        );

        return $code === false ? $state : $code;
    }

    /**
     * The date as Y-m-d when it is in one of DATE_FORMATS, otherwise the
     * state unchanged for the date_format rule to reject.
     */
    protected static function normalizedDate(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, (string) $state);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return $state;
    }

    protected static function hasLeft(Employee $employee): bool
    {
        return strtolower(trim((string) $employee->exit_status)) === 'yes';
    }

    private function employeeIdFor(mixed $empId): ?int
    {
        if (blank($empId)) {
            return null;
        }

        $id = Employee::query()->where('emp_id', $empId)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
