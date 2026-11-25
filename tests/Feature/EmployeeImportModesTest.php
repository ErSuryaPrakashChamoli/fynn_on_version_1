<?php

namespace Tests\Feature;

use App\Filament\Imports\EmployeeImporter;
use App\Filament\Imports\EmployeeUpdateImporter;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Lead;
use App\Models\User;
use App\Services\EmployeeInactivationService;
use App\Services\ReportingLineService;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The employee import runs as "Upload New Employees" (adds people not
 * on the list) or "Update Existing Employees" (changes people who are,
 * from a file downloaded in the same pop-up).
 *
 * Business Head BH ── Cluster Manager CM ┬ Manager M ── Team Leader TL ── Caller C
 *                                        └ Manager M2
 */
class EmployeeImportModesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $manager;

    private Employee $otherManager;

    private Employee $teamLeader;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->actingAs(User::factory()->create()->assignRole('Admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->businessHead = $this->employee('BH01', Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee('CM01', Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->manager = $this->employee('MG01', Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->otherManager = $this->employee('MG02', Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeader = $this->employee('TL01', Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->caller = $this->employee('CL01', Employee::DESIGNATION_CALLER, $this->teamLeader);
    }

    private function employee(string $empId, int $designation, ?Employee $boss = null): Employee
    {
        return Employee::factory()->create([
            'emp_id' => $empId,
            'designation' => $designation,
            'position' => 'Staff',
            'category' => '2500000',
            'cost_center' => 'kanak_kumar',
            'unit_name' => 'kanak_kumar',
            'exit_status' => 'no',
            'doj' => '2024-06-01',
            'reporting_date' => '2025-01-15',
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
        ]);
    }

    /**
     * Runs the rows through one importer, as a queued chunk does, and
     * returns each failed row's message keyed by its position.
     *
     * @param  list<array<string, string>>  $rows
     * @return array<int, string>
     */
    private function import(array $rows, string $mode, string $move = EmployeeImporter::MOVE_ALONE): array
    {
        // The two pop-ups: "Upload New Employees" passes the mode, "Update
        // Existing Employees" uses its own importer with the move choice.
        [$importerClass, $options] = $mode === EmployeeImporter::MODE_UPDATE
            ? [EmployeeUpdateImporter::class, ['move' => $move]]
            : [EmployeeImporter::class, ['mode' => $mode]];

        $import = Import::query()->forceCreate([
            'file_name' => 'employees.csv',
            'file_path' => 'employees.csv',
            'importer' => $importerClass,
            'total_rows' => count($rows),
            'user_id' => auth()->id(),
        ]);

        $columns = array_keys($rows[0]);
        $importer = new $importerClass($import, array_combine($columns, $columns), $options);
        $failures = [];

        foreach ($rows as $index => $row) {
            try {
                $importer($row);
            } catch (RowImportFailedException $exception) {
                $failures[$index] = $exception->getMessage();
            } catch (ValidationException $exception) {
                $failures[$index] = collect($exception->errors())->flatten()->implode(' ');
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>
     */
    private function updateRow(Employee $employee, array $cells = []): array
    {
        return [
            'emp_id' => $employee->emp_id,
            'emp_name' => '',
            'email' => '',
            'designation' => '',
            'doj' => '',
            'reporting_date' => '',
            'reports_to' => '',
            'cost_center' => '',
            'unit_name' => '',
            'status' => '',
            'inactive_date' => '',
            ...$cells,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function downloadedRows(): array
    {
        $reader = Reader::fromString(EmployeeImporter::existingEmployeesCsv());
        $reader->setHeaderOffset(0);

        return array_values(iterator_to_array($reader->getRecords(), false));
    }

    private function leave(Employee $employee, string $exitDate): void
    {
        app(EmployeeInactivationService::class)->inactivate($employee, Carbon::parse($exitDate), null, null);
    }

    /*
    |--------------------------------------------------------------------------
    | Upload New Employees
    |--------------------------------------------------------------------------
    */

    public function test_upload_new_adds_an_employee_with_a_designation_name_and_a_day_first_date(): void
    {
        $failures = $this->import([[
            'emp_id' => 'TL02',
            'emp_name' => 'New Team Leader',
            'email' => 'new.tl@example.com',
            'designation' => 'team leader',
            'doj' => '15-01-2025',
            'reports_to' => $this->otherManager->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertSame([], $failures);

        $added = Employee::query()->where('emp_id', 'TL02')->sole();

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $added->designation);
        $this->assertSame('2025-01-15', $added->doj);
        $this->assertSame($this->otherManager->id, $added->manager_id);
        $this->assertSame($this->cluster->id, $added->cluster_id);
    }

    public function test_upload_new_refuses_an_employee_who_already_exists(): void
    {
        $failures = $this->import([[
            'emp_id' => $this->caller->emp_id,
            'emp_name' => 'Somebody Else',
            'email' => 'somebody.else@example.com',
            'designation' => 'Caller',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertStringContainsString('already exists. Use "Update Existing Employees"', $failures[0]);
        $this->assertNotSame('Somebody Else', $this->caller->fresh()->emp_name);
    }

    public function test_upload_new_refuses_an_unknown_designation(): void
    {
        $failures = $this->import([[
            'emp_id' => 'XX01',
            'emp_name' => 'Unknown Role',
            'email' => 'unknown.role@example.com',
            'designation' => 'Astronaut',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertStringContainsString('Unknown designation', $failures[0]);
        $this->assertFalse(Employee::query()->where('emp_id', 'XX01')->exists());
    }

    public function test_upload_new_refuses_a_caller_without_a_target_category(): void
    {
        $failures = $this->import([[
            'emp_id' => 'XX03',
            'emp_name' => 'No Category',
            'email' => 'no.category@example.com',
            'designation' => 'Caller',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertStringContainsString('a caller needs a Target Category with a monthly target', $failures[0]);
        $this->assertFalse(Employee::query()->where('emp_id', 'XX03')->exists());
    }

    public function test_upload_new_takes_a_callers_target_category_by_name(): void
    {
        $failures = $this->import([[
            'emp_id' => 'XX04',
            'emp_name' => 'Gold Caller',
            'email' => 'gold.caller@example.com',
            'designation' => 'Caller',
            'target_category' => 'gold',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertSame([], $failures);
        $this->assertSame('3000000', Employee::query()->where('emp_id', 'XX04')->sole()->category);
    }

    public function test_upload_new_refuses_a_category_without_a_monthly_target_for_a_caller(): void
    {
        $failures = $this->import([[
            'emp_id' => 'XX05',
            'emp_name' => 'Alpha Caller',
            'email' => 'alpha.caller@example.com',
            'designation' => 'Caller',
            'target_category' => 'Alpha',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertStringContainsString('a caller needs a Target Category with a monthly target', $failures[0]);
    }

    public function test_an_update_row_never_changes_a_category_undated(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['target_category' => 'Diamond']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('Change Position / Target', $failures[0]);
        $this->assertSame('2500000', $this->caller->fresh()->category);
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $failures = $this->import([[
            'emp_id' => 'XX02',
            'emp_name' => 'Bad Date',
            'email' => 'bad.date@example.com',
            'designation' => 'Caller',
            'doj' => '31/02/2025',
            'reports_to' => $this->teamLeader->emp_id,
        ]], EmployeeImporter::MODE_CREATE);

        $this->assertStringContainsString('doj must be a date', $failures[0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Existing Employees
    |--------------------------------------------------------------------------
    */

    public function test_update_changes_only_the_filled_cells(): void
    {
        $email = $this->caller->email;
        $historyRows = EmployeeReportingHistory::query()->count();

        $failures = $this->import([
            $this->updateRow($this->caller, ['emp_name' => 'Renamed Caller', 'cost_center' => 'new_centre', 'doj' => '01/02/2024']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertSame([], $failures);

        $caller = $this->caller->fresh();

        $this->assertSame('Renamed Caller', $caller->emp_name);
        $this->assertSame('new_centre', $caller->cost_center);
        $this->assertSame('2024-02-01', $caller->doj);
        $this->assertSame($email, $caller->email);
        $this->assertSame(Employee::DESIGNATION_CALLER, $caller->designation);
        $this->assertSame('kanak_kumar', $caller->unit_name);
        $this->assertSame($this->teamLeader->id, $caller->superviser_id);
        $this->assertSame($historyRows, EmployeeReportingHistory::query()->count());
    }

    public function test_update_refuses_an_employee_who_is_not_on_the_list(): void
    {
        $failures = $this->import([
            ['emp_id' => 'NOPE01', 'emp_name' => 'Ghost', 'email' => 'ghost@example.com', 'designation' => 'Caller'],
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('No employee with ID NOPE01. Use "Upload New Employees"', $failures[0]);
        $this->assertFalse(Employee::query()->where('emp_id', 'NOPE01')->exists());
    }

    public function test_update_refuses_an_email_another_employee_already_has(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['email' => $this->teamLeader->email]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertArrayHasKey(0, $failures);
        $this->assertNotSame($this->teamLeader->email, $this->caller->fresh()->email);
    }

    public function test_moving_alone_is_refused_while_their_team_still_reports_to_them(): void
    {
        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['reports_to' => $this->otherManager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $this->assertStringContainsString('TL01: '.$this->teamLeader->emp_name.' still has 1 direct report', $failures[0]);
        $this->assertStringContainsString('Move the employee with their team', $failures[0]);
        $this->assertSame($this->manager->id, $this->teamLeader->fresh()->manager_id);
        $this->assertSame($this->manager->id, $this->caller->fresh()->manager_id);
    }

    public function test_moving_alone_moves_somebody_with_nothing_attached_as_a_transfer(): void
    {
        $otherTeamLeader = $this->employee('TL02', Employee::DESIGNATION_TEAM_LEADER, $this->otherManager);

        $failures = $this->import([
            $this->updateRow($this->caller, ['reports_to' => $otherTeamLeader->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame($otherTeamLeader->id, $caller->superviser_id);
        $this->assertSame($this->otherManager->id, $caller->manager_id);
        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $caller->id)
            ->where('change_type', ReportingLineService::CHANGE_TRANSFER)
            ->where('remarks', 'Moved by employee import.')
            ->exists());
    }

    public function test_moving_alone_takes_their_own_open_work_along(): void
    {
        $otherTeamLeader = $this->employee('TL02', Employee::DESIGNATION_TEAM_LEADER, $this->otherManager);
        $customer = Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'journey_status' => 'sfl',
            'disbursal_finalized' => false,
            'disbursal_status' => null,
        ]);
        $lead = Lead::query()->create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Open lead',
            'mobile_no' => '9876512345',
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'Followed up.',
        ]);

        $failures = $this->import([
            $this->updateRow($this->caller, ['reports_to' => $otherTeamLeader->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $this->assertSame([], $failures);
        $this->assertSame($otherTeamLeader->id, $this->caller->fresh()->superviser_id);
        $this->assertSame($this->caller->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->caller->id, (int) $lead->fresh()->employee_id);
    }

    public function test_moving_alone_takes_a_lead_somebody_else_created_along_too(): void
    {
        $otherTeamLeader = $this->employee('TL02', Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $lead = Lead::query()->create([
            'employee_id' => $otherTeamLeader->id,
            'created_by' => $this->caller->id,
            'customer_name' => 'Handed-over lead',
            'mobile_no' => '9876512346',
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'Followed up.',
        ]);

        $failures = $this->import([
            $this->updateRow($otherTeamLeader, ['reports_to' => $this->otherManager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $this->assertSame([], $failures);
        $this->assertSame($this->otherManager->id, $otherTeamLeader->fresh()->manager_id);
        $this->assertSame($otherTeamLeader->id, (int) $lead->fresh()->employee_id, 'Nothing is handed over while they stay on the rolls.');
    }

    public function test_moving_with_their_team_takes_everyone_under_them_along(): void
    {
        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['reports_to' => $this->otherManager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_WITH_TEAM);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame($this->otherManager->id, $this->teamLeader->fresh()->manager_id);
        $this->assertSame($this->teamLeader->id, $caller->superviser_id);
        $this->assertSame($this->otherManager->id, $caller->manager_id);
        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $this->teamLeader->id)
            ->where('change_type', ReportingLineService::CHANGE_TRANSFER)
            ->where('remarks', 'Moved with their team by employee import.')
            ->exists());
    }

    public function test_one_file_can_hand_the_team_over_and_then_move_their_boss_alone(): void
    {
        $otherTeamLeader = $this->employee('TL02', Employee::DESIGNATION_TEAM_LEADER, $this->otherManager);

        $failures = $this->import([
            $this->updateRow($this->caller, ['reports_to' => $otherTeamLeader->emp_id]),
            $this->updateRow($this->teamLeader, ['reports_to' => $this->otherManager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $this->assertSame([], $failures);
        $this->assertSame($otherTeamLeader->id, $this->caller->fresh()->superviser_id);
        $this->assertSame($this->otherManager->id, $this->teamLeader->fresh()->manager_id);
    }

    public function test_a_promotion_still_takes_the_team_along_when_moving_alone(): void
    {
        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['designation' => 'Manager', 'reports_to' => $this->cluster->emp_id]),
        ], EmployeeImporter::MODE_UPDATE, EmployeeImporter::MOVE_ALONE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->teamLeader->fresh()->designation);
        $this->assertNull($caller->superviser_id);
        $this->assertSame($this->teamLeader->id, $caller->manager_id);
    }

    public function test_a_promotion_is_dated_from_the_effective_date_given(): void
    {
        $effectiveDate = today()->subDays(5)->toDateString();

        $failures = $this->import([
            $this->updateRow($this->caller, ['designation' => 'Team Leader', 'reports_to' => $this->manager->emp_id, 'effective_date' => $effectiveDate]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertSame([], $failures);
        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->caller->fresh()->designation);

        foreach ([ReportingLineService::CHANGE_DESIGNATION, ReportingLineService::CHANGE_REPORTING] as $changeType) {
            $this->assertSame($effectiveDate, EmployeeReportingHistory::query()
                ->where('employee_id', $this->caller->id)
                ->where('change_type', $changeType)
                ->sole()
                ->effective_date
                ->toDateString());
        }
    }

    public function test_a_promotion_without_an_effective_date_is_dated_the_day_of_the_upload(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['designation' => 'Team Leader', 'reports_to' => $this->manager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertSame([], $failures);
        $this->assertSame(today()->toDateString(), EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole()
            ->effective_date
            ->toDateString());
    }

    public function test_an_effective_date_in_the_future_or_before_joining_is_refused(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['designation' => 'Team Leader', 'reports_to' => $this->manager->emp_id, 'effective_date' => today()->addDay()->toDateString()]),
            $this->updateRow($this->caller, ['designation' => 'Team Leader', 'reports_to' => $this->manager->emp_id, 'effective_date' => '2024-01-01']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('effective_date cannot be in the future', $failures[0]);
        $this->assertStringContainsString('effective_date cannot be before 15 Jan 2025', $failures[1]);
        $this->assertSame(Employee::DESIGNATION_CALLER, $this->caller->fresh()->designation);
    }

    public function test_update_refuses_a_boss_who_is_not_senior(): void
    {
        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['reports_to' => $this->caller->emp_id]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('is not senior to', $failures[0]);
        $this->assertSame($this->manager->id, $this->teamLeader->fresh()->manager_id);
    }

    public function test_update_refuses_demoting_a_boss_below_their_team(): void
    {
        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['designation' => 'Caller']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('would no longer have a more senior boss', $failures[0]);
        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->teamLeader->fresh()->designation);
    }

    public function test_an_untouched_row_under_a_boss_who_has_left_still_saves(): void
    {
        $this->teamLeader->forceFill(['exit_status' => 'yes'])->save();

        $failures = $this->import([
            $this->updateRow($this->caller, [
                'emp_name' => 'Still Here',
                'reports_to' => $this->teamLeader->emp_id,
            ]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertSame([], $failures);
        $this->assertSame('Still Here', $this->caller->fresh()->emp_name);
    }

    /*
    |--------------------------------------------------------------------------
    | Status and inactive date
    |--------------------------------------------------------------------------
    */

    public function test_update_marks_an_employee_inactive_from_the_given_date(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Inactive', 'inactive_date' => '31-10-2026']),
        ], EmployeeImporter::MODE_UPDATE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame('yes', $caller->exit_status);
        $this->assertSame('2026-10-31', $caller->exit_date);
        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $caller->id)
            ->where('change_type', 'exit')
            ->whereDate('effective_date', '2026-10-31')
            ->where('remarks', 'Marked inactive by employee import.')
            ->exists());
    }

    public function test_marking_an_employee_inactive_needs_a_date(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'inactive']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('inactive_date is required', $failures[0]);
        $this->assertSame('no', $this->caller->fresh()->exit_status);
    }

    public function test_marking_inactive_is_refused_while_the_team_still_reports_to_them_and_the_row_changes_nothing(): void
    {
        $name = $this->teamLeader->emp_name;

        $failures = $this->import([
            $this->updateRow($this->teamLeader, ['emp_name' => 'Renamed Leader', 'status' => 'Inactive', 'inactive_date' => '2026-10-31']),
        ], EmployeeImporter::MODE_UPDATE);

        $teamLeader = $this->teamLeader->fresh();

        $this->assertStringContainsString('still has 1 direct report', $failures[0]);
        $this->assertSame('no', $teamLeader->exit_status);
        $this->assertSame($name, $teamLeader->emp_name);
    }

    public function test_an_inactive_date_in_the_future_is_refused(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Inactive', 'inactive_date' => today()->addDay()->toDateString()]),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('cannot be in the future', $failures[0]);
        $this->assertSame('no', $this->caller->fresh()->exit_status);
    }

    public function test_an_active_row_with_an_inactive_date_is_refused(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Active', 'inactive_date' => '2026-10-31']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('inactive_date is only for Inactive employees', $failures[0]);
        $this->assertNull($this->caller->fresh()->exit_date);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'On leave']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('status must be Active or Inactive', $failures[0]);
    }

    public function test_update_brings_an_inactive_employee_back(): void
    {
        $this->leave($this->caller, '2026-10-31');

        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Active']),
        ], EmployeeImporter::MODE_UPDATE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame('no', $caller->exit_status);
        $this->assertNull($caller->exit_date);
    }

    public function test_bringing_somebody_back_needs_a_boss_still_on_the_rolls(): void
    {
        $this->leave($this->caller, '2026-10-30');
        $this->leave($this->teamLeader, '2026-10-31');

        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Active']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('has left and cannot be reported to', $failures[0]);
        $this->assertSame('yes', $this->caller->fresh()->exit_status);

        $failures = $this->import([
            $this->updateRow($this->caller, ['status' => 'Active', 'reports_to' => $this->otherManager->emp_id]),
        ], EmployeeImporter::MODE_UPDATE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame('no', $caller->exit_status);
        $this->assertSame($this->otherManager->id, $caller->manager_id);
    }

    public function test_update_corrects_the_inactive_date_of_somebody_who_has_left(): void
    {
        $this->leave($this->caller, '2026-10-31');

        $failures = $this->import([
            $this->updateRow($this->caller, ['inactive_date' => '15/10/2026']),
        ], EmployeeImporter::MODE_UPDATE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame('yes', $caller->exit_status);
        $this->assertSame('2026-10-15', $caller->exit_date);
    }

    public function test_a_corrected_inactive_date_cannot_be_before_they_joined(): void
    {
        $this->leave($this->caller, '2026-10-31');

        $failures = $this->import([
            $this->updateRow($this->caller, ['inactive_date' => '2024-01-01']),
        ], EmployeeImporter::MODE_UPDATE);

        $this->assertStringContainsString('cannot be before they joined', $failures[0]);
        $this->assertSame('2026-10-31', $this->caller->fresh()->exit_date);
    }

    public function test_upload_new_can_add_somebody_who_has_already_left(): void
    {
        $failures = $this->import([[
            'emp_id' => 'CL50',
            'emp_name' => 'Past Caller',
            'email' => 'past.caller@example.com',
            'designation' => 'Caller',
            'target_category' => 'Silver',
            'doj' => '2025-01-01',
            'reports_to' => $this->teamLeader->emp_id,
            'status' => 'Inactive',
            'inactive_date' => '2026-06-30',
        ]], EmployeeImporter::MODE_CREATE);

        $added = Employee::query()->where('emp_id', 'CL50')->sole();

        $this->assertSame([], $failures);
        $this->assertSame('yes', $added->exit_status);
        $this->assertSame('2026-06-30', $added->exit_date);
    }

    /*
    |--------------------------------------------------------------------------
    | Download existing data
    |--------------------------------------------------------------------------
    */

    public function test_the_download_lists_every_employee_with_their_boss_and_status(): void
    {
        $leaver = $this->employee('CL99', Employee::DESIGNATION_CALLER, $this->teamLeader);
        $this->leave($leaver, '2026-10-31');

        $rows = collect($this->downloadedRows())->keyBy('emp_id');

        $this->assertSame(
            ['emp_id', 'emp_name', 'email', 'designation', 'target_category', 'doj', 'reporting_date', 'reports_to', 'cost_center', 'unit_name', 'status', 'inactive_date', 'effective_date'],
            array_keys($rows->first()),
        );
        $this->assertSame('', $rows['TL01']['target_category'], 'Only a caller carries a Target Category.');
        $this->assertSame('Caller', $rows['CL01']['designation']);
        $this->assertSame('TL01', $rows['CL01']['reports_to']);
        $this->assertSame('', $rows['BH01']['reports_to']);
        $this->assertSame(['Active', ''], [$rows['CL01']['status'], $rows['CL01']['inactive_date']]);
        $this->assertSame(['Inactive', '2026-10-31'], [$rows['CL99']['status'], $rows['CL99']['inactive_date']]);
    }

    public function test_the_download_lists_the_lowest_level_first(): void
    {
        $this->assertSame(
            ['CL01', 'TL01', 'MG01', 'MG02', 'CM01', 'BH01'],
            array_column($this->downloadedRows(), 'emp_id'),
        );
    }

    public function test_the_downloaded_file_uploads_back_without_changing_anything(): void
    {
        $this->leave($this->caller, '2026-10-31');

        $before = Employee::query()->orderBy('id')->get()->toArray();
        $historyRows = EmployeeReportingHistory::query()->count();

        $failures = $this->import($this->downloadedRows(), EmployeeImporter::MODE_UPDATE);

        $this->assertSame([], $failures);
        $this->assertSame($before, Employee::query()->orderBy('id')->get()->toArray());
        $this->assertSame($historyRows, EmployeeReportingHistory::query()->count());
    }

    public function test_an_edited_download_updates_just_the_edited_rows(): void
    {
        $rows = collect($this->downloadedRows())
            ->map(fn (array $row): array => $row['emp_id'] === 'CL01'
                ? [...$row, 'emp_name' => 'Edited In Excel', 'reports_to' => 'MG02']
                : $row)
            ->all();

        $failures = $this->import($rows, EmployeeImporter::MODE_UPDATE);

        $caller = $this->caller->fresh();

        $this->assertSame([], $failures);
        $this->assertSame('Edited In Excel', $caller->emp_name);
        $this->assertNull($caller->superviser_id);
        $this->assertSame($this->otherManager->id, $caller->manager_id);
        $this->assertSame($this->manager->id, $this->teamLeader->fresh()->manager_id);
    }

    public function test_an_admin_downloads_existing_data_from_the_update_pop_up(): void
    {
        Livewire::test(ListEmployees::class)
            ->assertActionVisible(TestAction::make('importNewEmployees')->table())
            ->assertActionVisible(TestAction::make('updateExistingEmployees')->table())
            ->callAction([
                TestAction::make('updateExistingEmployees')->table(),
                TestAction::make('downloadExistingEmployees'),
            ])
            ->assertFileDownloaded('employees-'.now()->format('Y-m-d').'.csv');
    }

    public function test_only_the_update_pop_up_asks_how_people_move(): void
    {
        Livewire::test(ListEmployees::class)
            ->mountAction(TestAction::make('updateExistingEmployees')->table())
            ->assertFormFieldExists('move')
            ->assertActionDataSet(['move' => EmployeeImporter::MOVE_ALONE]);

        Livewire::test(ListEmployees::class)
            ->mountAction(TestAction::make('importNewEmployees')->table())
            ->assertFormFieldDoesNotExist('move');
    }

    public function test_only_an_admin_can_update_existing_employees(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'Manager'])));

        Livewire::test(ListEmployees::class)
            ->assertActionHidden(TestAction::make('updateExistingEmployees')->table());
    }
}
